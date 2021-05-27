<?php
declare(strict_types=1);

namespace Smichaelsen\MelonImages\Domain\Dto;

use TYPO3Fluid\Fluid\Core\ViewHelper\TagBuilder;

class Source
{
    protected Dimensions $dimensions;

    protected string $mediaQuery = '';

    /**
     * @var Set[]
     */
    protected $sets = [];

    public function __construct(string $mediaQuery, Dimensions $dimensions)
    {
        $this->dimensions = $dimensions;
        $this->mediaQuery = $mediaQuery;
    }

    public function getMediaQuery(): string
    {
        return $this->mediaQuery;
    }

    public function addSet(Set $set)
    {
        $this->sets[] = $set;
    }

    public function getSets(): array
    {
        return $this->sets;
    }

    public function getDimensions(): Dimensions
    {
        return $this->dimensions;
    }

    public function getSrcsets(): array
    {
        return $this->sets;
    }

    public function getSrcsetsString(): string
    {
        $strings = [];
        foreach ($this->sets as $set)
        {
            $strings[] = $set->__toString();
        }
        return implode(', ', $strings);
    }

    public function getHtml(): string
    {
        $tag = new TagBuilder('source');
        $tag->addAttribute('srcset', $this->getSrcsetsString());
        if (!empty($this->mediaQuery)) {
            $tag->addAttribute('media', $this->mediaQuery);
        }
        return $tag->render();
    }
}
