<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

final readonly class MenuItem
{
    /**
     * @param list<MenuItem> $children
     */
    public function __construct(
        public ?int $id,
        public int $menuId,
        public string $label,
        public MenuTargetType $targetType,
        public string $targetValue,
        public ?int $parentId = null,
        public int $sortOrder = 0,
        public MenuVisibility $visibility = MenuVisibility::Alle,
        public array $children = [],
        /** Beim Rendern aufgeloester Pfad; leer, wenn das Ziel nicht mehr existiert. */
        public string $resolvedPath = '',
    ) {}

    public function isExternal(): bool
    {
        return $this->targetType === MenuTargetType::Url;
    }

    /**
     * @param list<MenuItem> $children
     */
    public function withResolved(string $path, array $children): self
    {
        return new self(
            $this->id,
            $this->menuId,
            $this->label,
            $this->targetType,
            $this->targetValue,
            $this->parentId,
            $this->sortOrder,
            $this->visibility,
            $children,
            $path,
        );
    }
}
