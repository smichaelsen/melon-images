<?php

declare(strict_types=1);

namespace Smichaelsen\MelonImages\Domain\Dto;

use TYPO3Fluid\Fluid\Core\ViewHelper\TagBuilder;

class Source implements \JsonSerializable
{
    protected Dimensions $dimensions;

    protected string $mediaQuery = '';

    /**
     * @var Set[]
     */
    protected array $sets = [];

    protected ?string $type = null;

    public function __construct(string $mediaQuery, Dimensions $dimensions, ?string $type = null)
    {
        $this->dimensions = $dimensions;
        $this->mediaQuery = $mediaQuery;
        $this->type = $type;
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
        foreach ($this->sets as $set) {
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
        if ($this->type !== null) {
            $tag->addAttribute('type', $this->type);
        }
        return $tag->render();
    }

    public function jsonSerialize(): array
    {
        $return = [
            'srcsets' => $this->sets,
            'mediaQuery' => $this->mediaQuery,
        ];

        $height = $this->dimensions->getHeight();
        if ($height !== null) {
            $return['height'] = $height;
        }
        $width = $this->dimensions->getWidth();
        if ($width !== null) {
            $return['width'] = $width;
        }
        if ($this->type === null && $this->sets[0] instanceof Set) {
            $n = strrpos($this->sets[0]->getImageUri(), '.');
            $fileExtension = ($n === false) ? '' : substr($this->sets[0]->getImageUri(), $n + 1);
            $return['type'] = $this->getTypeFromExtension($fileExtension);
        } elseif ($this->type !== null) {
            $return['type'] = $this->type;
        }

        return $return;
    }

    private function getTypeFromExtension(string $fileExtension): string
    {
        switch ($fileExtension) {
            case 'jpg':
            case 'jpeg':
                return 'image/jpeg';
            case 'png':
                return 'image/png';
            case 'gif':
                return 'image/gif';
            case 'webp':
                return 'image/webp';
            default:
                return 'image/' . $fileExtension;
        }
    }
}
