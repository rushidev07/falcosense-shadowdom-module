<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Plp;

/**
 * One filter group as the platform reports it for the current listing —
 * attribute, its options, and how many results each option would leave.
 * Consumed by the widget to build the (shadow-DOM) filter rail; the server
 * render only uses it for a minimal progressive-enhancement `<select>`/`<a>`
 * fallback, never the rich UI.
 */
final class PlpFacet
{
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_SWATCH   = 'swatch';
    public const TYPE_PRICE    = 'price';

    /**
     * @param array<int, array{value: string, label: string, count: int}> $options
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly array $options,
    ) {
    }

    public function toArray(): array
    {
        return [
            'key'     => $this->key,
            'label'   => $this->label,
            'type'    => $this->type,
            'options' => $this->options,
        ];
    }

    public static function fromArray(array $d): self
    {
        $options = [];
        foreach ((array) ($d['options'] ?? []) as $opt) {
            $value = (string) ($opt['value'] ?? $opt['label'] ?? '');
            if ($value === '') {
                continue;
            }
            $options[] = [
                'value' => $value,
                'label' => (string) ($opt['label'] ?? $opt['value'] ?? $value),
                'count' => (int) ($opt['count'] ?? 0),
            ];
        }

        return new self(
            key: (string) ($d['key'] ?? $d['attribute'] ?? ''),
            label: (string) ($d['label'] ?? $d['key'] ?? ''),
            type: (string) ($d['type'] ?? self::TYPE_CHECKBOX),
            options: $options,
        );
    }
}
