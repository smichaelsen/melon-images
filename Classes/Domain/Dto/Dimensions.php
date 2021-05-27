<?php
declare(strict_types=1);

namespace Smichaelsen\MelonImages\Domain\Dto;

use TYPO3\CMS\Core\Utility\MathUtility;

class Dimensions
{
    protected ?float $height;

    protected ?float $ratio;

    protected ?float $width;

    public function __construct(?float $width, ?float $height, $ratio = null)
    {
        if (is_string($ratio)) {
            $ratio = (float)MathUtility::calculateWithParentheses($ratio);
        }

        if ($height !== null && $ratio !== null && $width === null) {
            $width = $height * $ratio;
        }
        if ($height !== null && $ratio === null && $width !== null) {
            $ratio = $width / $height;
        }
        if ($height === null && $ratio !== null && $width !== null) {
            $height = $width / $ratio;
        }

        $this->height = $height;
        $this->ratio = $ratio;
        $this->width = $width;
    }

    public function getHeight(): ?float
    {
        return $this->height;
    }

    public function getRatio(): ?float
    {
        return $this->ratio;
    }

    public function getWidth(): ?float
    {
        return $this->width;
    }

    public function isFree(): bool
    {
        return $this->ratio === null;
    }

    public function scale(float $factor): Dimensions
    {
        return new Dimensions(
            $this->width === null ? null : $this->width * $factor,
            $this->height === null ? null : $this->height * $factor
        );
    }
}
