<?php
declare(strict_types=1);

namespace Smichaelsen\MelonImages\Domain\Dto;

class Dimensions
{
    protected $height = null;

    protected $width = null;

    public function __construct(?int $width, ?int $height)
    {
        $this->height = $height;
        $this->width = $width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }
}
