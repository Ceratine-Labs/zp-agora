<?php

namespace Modules\Email\Services;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Modules\Email\Support\RenderedTemplate;
use Modules\Email\Support\TemplateDefinition;

/** A deliberately small data language: known {{variables}}, never PHP or Blade. */
final class TemplateMarkup
{
    private const TOKEN = '/\{\{\s*([a-zA-Z][a-zA-Z0-9_.]*)\s*\}\}/';

    /** @return list<string> */
    public function validate(TemplateDefinition $definition, string $subject, string $html, string $text): array
    {
        $used = [];
        foreach (['Subject' => $subject, 'BodyHtml' => $html, 'BodyText' => $text] as $field => $body) {
            preg_match_all(self::TOKEN, $body, $matches);
            $unknown = array_diff($matches[1], array_keys($definition->variables));
            if ($unknown !== []) {
                $this->refuse($field, 'Unknown variable: '.implode(', ', array_unique($unknown)).'.');
            }
            $literal = preg_replace(self::TOKEN, '', $body);
            if (str_contains($literal, '{{') || str_contains($literal, '}}') || str_contains($literal, '<?')) {
                $this->refuse($field, 'Use only the listed variables in double braces; expressions are not supported.');
            }
            $used = array_merge($used, $matches[1]);
        }
        if (preg_match('/[\r\n]/', $subject)) {
            $this->refuse('Subject', 'The subject must be one line.');
        }
        $this->validateHtml($definition, $html);
        $used = array_values(array_unique($used));
        sort($used);

        return $used;
    }

    /** @param array<string, mixed> $values */
    public function render(TemplateDefinition $definition, string $subject, string $html, string $text, array $values): RenderedTemplate
    {
        $used = $this->validate($definition, $subject, $html, $text);
        $values = array_merge(Arr::dot($values), $values);
        foreach ($used as $name) {
            if (! array_key_exists($name, $values) || (! is_scalar($values[$name]) && $values[$name] !== null)) {
                $this->refuse('variables', 'A value is required for '.$name.'.');
            }
            if (($definition->variables[$name]['type'] ?? 'text') === 'url' && ! $this->safeUrl((string) $values[$name])) {
                $this->refuse('variables', 'A valid HTTP or HTTPS URL is required for '.$name.'.');
            }
        }
        $replace = fn (string $body, bool $escape): string => preg_replace_callback(self::TOKEN,
            fn (array $match): string => $escape
                ? htmlspecialchars((string) $values[$match[1]], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : (string) $values[$match[1]], $body);

        return new RenderedTemplate(
            str_replace(["\r", "\n"], ' ', $replace($subject, false)),
            $replace($html, true),
            $replace($text, false),
        );
    }

    private function validateHtml(TemplateDefinition $definition, string $html): void
    {
        // Outlook executes conditional comments that a browser DOM treats as inert.
        // No declarations/comments belong to this small editable HTML language.
        if (str_contains($html, '<!')) {
            $this->refuse('BodyHtml', 'HTML comments and declarations are not supported.');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $allowed = ['html', 'body', 'p', 'br', 'strong', 'em', 'b', 'i', 'u', 'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'a'];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement || ! in_array($element->tagName, $allowed, true)) {
                $this->refuse('BodyHtml', 'Use simple email HTML only: paragraphs, headings, lists, tables and links.');
            }
            foreach ($element->attributes as $attribute) {
                if ($element->tagName !== 'a' || $attribute->name !== 'href') {
                    $this->refuse('BodyHtml', 'Only href attributes on links are supported; scripts, styles and embedded content are not allowed.');
                }
                $href = $attribute->value;
                if (preg_match('/^\{\{\s*([a-zA-Z][a-zA-Z0-9_.]*)\s*\}\}$/', $href, $match)) {
                    if (($definition->variables[$match[1]]['type'] ?? '') !== 'url') {
                        $this->refuse('BodyHtml', 'Links must use a registered URL variable or an HTTP/HTTPS address.');
                    }
                } elseif (! $this->safeUrl($href) || str_contains($href, '{{')) {
                    $this->refuse('BodyHtml', 'Links must use a registered URL variable or an HTTP/HTTPS address.');
                }
            }
        }
    }

    private function safeUrl(string $url): bool
    {
        return ! preg_match('/[\x00-\x20\x7f]/', $url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
