<?php

declare(strict_types=1);

namespace Smichaelsen\MelonImages\Domain\Dto;

class Set implements \JsonSerializable, \Stringable
{
    protected string $imageUri = '';

    protected float $pixelDensity = 1.0;

    public function getImageUri(): string
    {
        return $this->imageUri;
    }

    public function setImageUri(string $imageUri): void
    {
        $this->imageUri = $imageUri;
    }

    public function getPixelDensity(): float
    {
        return $this->pixelDensity;
    }

    public function setPixelDensity(float $pixelDensity): void
    {
        $this->pixelDensity = $pixelDensity;
    }

    public function __toString(): string
    {
        return $this->imageUri . ' ' . $this->pixelDensity . 'x';
    }

    public function jsonSerialize(): array
    {
        return [
            'uri' => $this->imageUri,
            'pixelDensity' => $this->pixelDensity,
        ];
    }
}
