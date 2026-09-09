<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ChargesForGeneratedFile;
use App\Services\GeneratedAttachmentTracker;
use Dompdf\Dompdf;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Same shape as the other generation tools. The model supplies a title plus a list
 * of (heading, body) sections rather than raw HTML — this tool owns the actual HTML
 * structure so every generated PDF looks consistent, and the model never has to get
 * markup right.
 */
class GeneratePdfTool implements Tool
{
    use ChargesForGeneratedFile;

    private const PRICING_MODEL_ID = 'document-pdf';

    // Same brand violet as GeneratePresentationTool (Alveta's own --primary) — a
    // generated PDF was plain unstyled <h1>/<h2>/<p> tags with no CSS at all before
    // this, rendering as bare black-on-white text with no visual hierarchy beyond
    // font size. dompdf supports real CSS, so there was no reason it looked that raw.
    private const ACCENT = '#6C37E6';
    private const TEXT_DARK = '#2B2438';
    private const TEXT_MUTED = '#6B6478';

    public function __construct(
        private string $userId,
        private GeneratedAttachmentTracker $tracker,
    ) {}

    public function description(): Stringable|string
    {
        return 'Generates a downloadable PDF document. Call this whenever the user asks for '
            .'a PDF, document, report, or summary to download — do not just write it out in '
            .'the chat instead.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Title of the document, shown at the top and used as its filename.')
                ->required(),
            'sections' => $schema->array()
                ->items($schema->object([
                    'heading' => $schema->string()->description('Section heading.')->required(),
                    'body' => $schema->string()->description('Section body text (plain text, paragraphs separated by newlines).')->required(),
                ]))
                ->description('The document content, broken into sections in order.')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $title = (string) $request['title'];
        $sections = $request['sections'];

        return $this->chargeAndStore(
            userId: $this->userId,
            pricingModelId: self::PRICING_MODEL_ID,
            filename: (Str::slug($title) ?: Str::random(10)).'.pdf',
            mimeType: 'application/pdf',
            generate: function () use ($title, $sections) {
                $style = '
                    body { font-family: Helvetica, Arial, sans-serif; color: '.self::TEXT_DARK.'; padding: 20px 10px; line-height: 1.5; }
                    h1 { color: '.self::ACCENT.'; font-size: 28px; margin: 0 0 6px; border-bottom: 3px solid '.self::ACCENT.'; padding-bottom: 14px; }
                    h2 { color: '.self::TEXT_DARK.'; font-size: 18px; margin: 28px 0 10px; padding-left: 10px; border-left: 4px solid '.self::ACCENT.'; }
                    p { font-size: 12px; margin: 0 0 10px; color: '.self::TEXT_MUTED.'; }
                ';
                $html = '<html><head><style>'.$style.'</style></head><body>';
                $html .= '<h1>'.e($title).'</h1>';

                foreach ($sections as $section) {
                    $html .= '<h2>'.e($section['heading']).'</h2>';
                    $paragraphs = preg_split('/\n+/', trim((string) $section['body']));
                    foreach ($paragraphs as $paragraph) {
                        if ($paragraph !== '') {
                            $html .= '<p>'.e($paragraph).'</p>';
                        }
                    }
                }
                $html .= '</body></html>';

                $dompdf = new Dompdf();
                $dompdf->loadHtml($html);
                $dompdf->setPaper('A4');
                $dompdf->render();

                return $dompdf->output();
            },
            tracker: $this->tracker,
            unavailableMessage: 'PDF generation is temporarily unavailable. Please try again later.',
            generationFailedMessage: 'PDF generation failed. Please try rephrasing your request.',
            successMessage: 'PDF generated successfully and shown to the user.',
        );
    }
}
