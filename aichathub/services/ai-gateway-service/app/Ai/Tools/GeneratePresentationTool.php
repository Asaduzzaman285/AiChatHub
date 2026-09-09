<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ChargesForGeneratedFile;
use App\Services\GeneratedAttachmentTracker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\AutoShape;
use PhpOffice\PhpPresentation\Slide\Background\Color as SlideBackground;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;
use PhpOffice\PhpPresentation\Writer\PowerPoint2007;
use Stringable;

/**
 * Same shape as GenerateImageTool/GenerateSpreadsheetTool. The model supplies a
 * structured list of slides (title + bullet points) rather than free text, since
 * PhpPresentation builds real slide shapes rather than rendering prose.
 */
class GeneratePresentationTool implements Tool
{
    use ChargesForGeneratedFile;

    private const PRICING_MODEL_ID = 'document-pptx';

    // Alveta's own UI accent (the "Violet Ink" theme's --primary, hsl(258 78% 56%))
    // converted to ARGB — confirmed live: without any real color/theme applied, a
    // generated deck was 27 slides of plain black text on bare white, no cover, no
    // visual identity at all beyond "technically a valid .pptx." Reusing the app's
    // own brand color rather than inventing an unrelated palette for generated files.
    private const ACCENT = 'FF6C37E6';
    private const TEXT_DARK = 'FF2B2438';
    private const WHITE = 'FFFFFFFF';

    public function __construct(
        private string $userId,
        private GeneratedAttachmentTracker $tracker,
    ) {}

    public function description(): Stringable|string
    {
        return 'Generates a downloadable PowerPoint (.pptx) presentation file. Call this '
            .'whenever the user asks for a presentation, slide deck, or slides to download — '
            .'do not just describe the slides in the chat instead.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Title of the presentation — shown on its own cover slide and used as the filename.')
                ->required(),
            'slides' => $schema->array()
                ->items($schema->object([
                    'title' => $schema->string()->description('Slide title.')->required(),
                    'bullets' => $schema->array()
                        ->items($schema->string())
                        ->description('Bullet points for this slide.')
                        ->required(),
                ]))
                ->description('The slides to generate, in order.')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $title = (string) $request['title'];
        $slides = $request['slides'];

        return $this->chargeAndStore(
            userId: $this->userId,
            pricingModelId: self::PRICING_MODEL_ID,
            filename: (Str::slug($title) ?: Str::random(10)).'.pptx',
            mimeType: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            generate: function () use ($title, $slides) {
                $presentation = new PhpPresentation();
                $presentation->removeSlideByIndex(0);

                $accent = new Color(self::ACCENT);
                $darkText = new Color(self::TEXT_DARK);
                $white = new Color(self::WHITE);

                // Cover slide — `title` used to exist only as the filename, never
                // shown anywhere inside the deck itself, so every generated
                // presentation opened straight into a content slide with nothing
                // resembling a first slide. A solid-color cover with the title
                // centered gives it the same "designed" first impression a real
                // deck starts with.
                $cover = $presentation->createSlide();
                $cover->setBackground((new SlideBackground())->setColor($accent));
                $coverTitle = $cover->createRichTextShape()
                    ->setHeight(160)->setWidth(880)->setOffsetX(40)->setOffsetY(190);
                $coverTitle->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $coverTitle->getActiveParagraph()->getFont()->setSize(40)->setBold(true)->setColor($white);
                $coverTitle->createTextRun($title);

                foreach ($slides as $slideData) {
                    $slide = $presentation->createSlide();

                    $titleShape = $slide->createRichTextShape()
                        ->setHeight(70)->setWidth(880)->setOffsetX(40)->setOffsetY(30);
                    $titleShape->getActiveParagraph()->getFont()->setSize(26)->setBold(true)->setColor($accent);
                    $titleShape->createTextRun((string) $slideData['title']);

                    // Thin accent rule between title and body — the one visual
                    // separator every content slide previously had none of.
                    $rule = new AutoShape();
                    $rule->setType(AutoShape::TYPE_RECTANGLE)
                        ->setHeight(3)->setWidth(880)->setOffsetX(40)->setOffsetY(96);
                    $rule->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor($accent);
                    $slide->addShape($rule);

                    $bodyShape = $slide->createRichTextShape()
                        ->setHeight(400)->setWidth(880)->setOffsetX(40)->setOffsetY(120);

                    foreach ($slideData['bullets'] as $index => $bullet) {
                        $paragraph = $index === 0 ? $bodyShape->getActiveParagraph() : $bodyShape->createParagraph();
                        $paragraph->getBulletStyle()->setBulletType(Bullet::TYPE_BULLET)->setBulletColor($accent);
                        $paragraph->getFont()->setSize(18)->setColor($darkText);
                        $paragraph->createTextRun((string) $bullet);
                    }
                }

                $tmpPath = tempnam(sys_get_temp_dir(), 'pptx_');
                (new PowerPoint2007($presentation))->save($tmpPath);
                $bytes = file_get_contents($tmpPath);
                unlink($tmpPath);

                return $bytes;
            },
            tracker: $this->tracker,
            unavailableMessage: 'Presentation generation is temporarily unavailable. Please try again later.',
            generationFailedMessage: 'Presentation generation failed. Please try rephrasing your request.',
            successMessage: 'Presentation generated successfully and shown to the user.',
        );
    }
}
