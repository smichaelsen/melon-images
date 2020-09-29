# Melon Images
**Responsive Images Management for TYPO3**

This package uses the powerful responsive image cropping capabilities of TYPO3 and provides easy frontend rendering.

![Image Cropping](doc/image-cropping.png?raw=true "Image Cropping")

TYPO3 8.7 comes with the powerful feature of `cropVariant`s, which lets you define use cases for your image including `allowedAspectRatios` and optionally `coverAreas`.
This package simplifies the configuration and frontend rendering of this feature.

## Configuration:

The configuration happens completely in TypoScript. This example configures 4 **variants** of the `tx_news_domain_model_news.fal_media` field,
that are *detail*, *featured*, *teaser* and *square*. The use case is we want to use the same image in different views with different cropping. Each
variant can also have different **sizes**. The *detail* variant for example is available in the sizes *big* (for tablet and desktop
viewport sizes) and *phone*.

> See "TypoScript Reference" bellow for a more detailed explanation

```
package.Smichaelsen\MelonImages {
  breakpoints {
    # phone from 0 to 479
    phone.to = 479
    # tablet from 480 to 1023
    tablet.from = 480
    tablet.to = 1023
    # desktop from 1024
    desktop.from = 1024
  }

  # render images in 1x and 2x
  pixelDensities = 1,2

  croppingConfiguration {
    tx_news_domain_model_news {
      # "_all" means for all news types
      _all {
        fal_media {
          variants {
            detail {
              sizes {
                big {
                  breakpoints = tablet,desktop
                  width = 943
                  height = 419
                }

                phone {
                  breakpoints = phone
                  # For the phone screen size 3:2 or 2:3 ratio is allowed
                  allowedRatios {
                    3by2 {
                      title = 3:2
                      width = 480
                      height = 320
                    }
                    2by3 {
                      title = 2:3
                      width = 320
                      height = 480
                    }
                  }
                }
              }
            }

            featured {
              sizes {
                desktop {
                  breakpoints = desktop
                  width = 1280
                  ratio = 16/9
                }

                # The desktop and tablet size share the same ratio and will therefore be grouped in the backend with the name "Featured 16/9"
                tablet {
                  breakpoints = tablet
                  width = 748
                  ratio = 16/9
                }

                phone {
                  breakpoints = phone
                  width = 480
                  height = 400
                }
              }
            }

            teaser {
              sizes {
                all {
                  width = 480
                  height = 420
                  coverAreas.1 {
                    x = 0.3
                    width = 0.7
                    y = 0.8
                    height = 0.2
                  }
                }
              }
            }

            square {
              title = Square for Open Graph
              sizes {
                all {
                  width = 512
                  height = 512
                }
              }
            }
          }
        }
      }
    }
  }
}

```

## Rendering

### Auto Render

To render the reponsive image with the correct cropping use the **ResponsivePictureViewHelper**:

```
<html
    xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
    xmlns:melon="http://typo3.org/ns/Smichaelsen/MelonImages/ViewHelpers"
    data-namespace-typo3-fluid="true"
>

<melon:responsivePicture fileReference="{newsItem.falMedia.0}" variant="featured"/>

</html>

```

The rendering (with the above TypoScript config) looks something like this:

```
<picture>
    <source srcset="fileadmin/_processed_/e/d/myimage_a6510d9ea7.jpg 1x, fileadmin/_processed_/e/d/myimage_7ca6b4a05b.jpg 2x" media="(min-width: 480px) and (max-width: 1023px), (min-width: 1024px)">
    <source srcset="fileadmin/_processed_/e/d/myimage_e9798f5526.jpg 1x, fileadmin/_processed_/e/d/myimage_23053285d0.jpg 2x" media="(max-width: 479px)">
    <img src="fileadmin/_processed_/e/d/myimage_712c5e4398.jpg" alt="">
</picture>
```

### Custom markup

The rendering as responsive `<picture>` tag is not always desirable. You can also get the data of the sources and fallback image and use it in your own markup:

```
<html
    xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
    xmlns:melon="http://typo3.org/ns/Smichaelsen/MelonImages/ViewHelpers"
    xmlns:n="http://typo3.org/ns/GeorgRinger/News/ViewHelpers"
    data-namespace-typo3-fluid="true"
>

<melon:responsivePicture fileReference="{newsItem.falMedia.0}" variant="square" as="pictureData">
    <n:metaTag property="og:image" content="{pictureData.fallbackImage.src}" forceAbsoluteUrl="1" />
    <n:metaTag property="og:image:width" content="{pictureData.fallbackImage.dimensions.width}" />
    <n:metaTag property="og:image:height" content="{pictureData.fallbackImage.dimensions.height}" />
</melon:responsivePicture>

</html>

```

The rendering looks something like this:

```
<meta property="og:image" content="https://www.example.com/fileadmin/_processed_/e/d/myimage_e7a4c74e8b.jpg" />
<meta property="og:image:width" content="512" />
<meta property="og:image:height" content="512" />
```

## ViewHelper Reference

### `\Smichaelsen\MelonImages\ViewHelpers\ResponsivePictureViewHelper`

Arguments:

* `fileReference`: Accepts a `\TYPO3\CMS\Core\Resource\FileReference`, a `\TYPO3\CMS\Extbase\Domain\Model\FileReference`, a `sys_file_reference`record array or a file reference uid.
* `variant`: Name of the variant to render. The names of variants are arbitrarily chosen in your TypoScript setup.
* `fallbackImageSize`: If you have an image with multiple sizes, by the last one (order is determined by order in TypoScript) will be used as fallback, i.e. for browsers [that do not support srcset](https://caniuse.com/#feat=picture). Here you can specify which size to use as fallback.
* `as`: The image data will be available with this variable name for custom markup rendering.
* `additionalImageAttributes`: Array of attributes that is applied to the `<img/>` tag.
* `absolute`: Set to `true` if you want image URLs to be absolute.
* `useCroppingFrom`: Here you can provide an additional FileReference that is used to crop the `fileReference`. Useful if you want to crop 2 images in exactly the same way. Accepts file references in the same form as `fileReference`.
* Universal tag attributes (`class`, `id`, `title`, ...) are available to add attributes to the `<picture/>` tag.

## TypoScript Reference

### 1. Breakpoints

`package.Smichaelsen\MelonImages.breakpoints`

Array of breakpoint names you can arbitrarily choose. Reference their names in the *Size Configuration* to indicate which is image size is intended for which breakpoint.

#### 1.1 Breakpoint range

`package.Smichaelsen\MelonImages.breakpoints.<breakpointName>`

`from` (optional): The lower end of the breakpoint range in pixels. <br>
`to` (optional): Indicates the upper end of the breakpoint range in pixels.

The breakpoint range is used for a media query in the responsive picture rendering in the frontend.
Make sure your breakpoint ranges follow each other without gap or overlapping to ensure correct frontend rendering.

Example:

```
package.Smichaelsen\MelonImages.breakpoints {
  # phone from 0 to 479
  phone.to = 479
  # tablet from 480 to 1023
  tablet.from = 480
  tablet.to = 1023
  # desktop from 1024
  desktop.from = 1024
}
```

### 2. Pixel Densities

`package.Smichaelsen\MelonImages.pixelDensities`

Comma separated list of pixel densities that are rendered in the responsive picture elements.
If you don't know what values you should put here `1,2` is good choice.
It renders images in standard size (`1`) and double size for displays that support double pixel density (`2`).

Example:

```
package.Smichaelsen\MelonImages.pixelDensities = 1,2
```

### 3. Per Table and Type Configuration

`package.Smichaelsen\MelonImages.croppingConfiguration.<table>.<recordType>`

If the configuration should apply to all records of the `<table>` regardless of the `<recordType>`, use `_all` as record type.

Example:

```
package.Smichaelsen\MelonImages.croppingConfiguration {
  tx_news_domain_model_news._all {
    # "_all" means for all news types
  }
  tt_content.textmedia {
    # applies only to tt_content records with type (CType) textmedia
  }
}
```

### 4. Per Field Configuration

`package.Smichaelsen\MelonImages.croppingConfiguration.<table>.<recordType>.<fieldName>`

The field must be:

1. Either a FAL field (i.e. an `inline` type field holding `sys_file_reference` records)

**or**

2. A field of the type `inline`, `select` or `group` referencing other records (See *7. Inline Records*)

### 5. Variants

`package.Smichaelsen\MelonImages.croppingConfiguration.<table>.<recordType>.<fieldName>.variants`

Array of variant names you can arbitrarily choose. Think of a variant as of situations you want to use an image in.
If want to use it in a list view, a detail view and a social media sharing format, your structure could look like in this example:

```
package.Smichaelsen\MelonImages.croppingConfiguration.tx_news_domain_model_news._all.variants {
  list {
    # ...
  }
  detail {
    # ...
  }
  openGraph {
    # ...
  }
}
```

Reuse the variant name in the `variant` attribute of the `melon:responsivePicture` ViewHelper.

### 5.1 Variant Configuration

`package.Smichaelsen\MelonImages.croppingConfiguration.<table>.<recordType>.<fieldName>.variants.<variantName>`

`title` (optional): Title of the variant that is shown to the backend user. Per default the variant name (with the first letter uppercased) is used.

### 6. Sizes

`package.Smichaelsen\MelonImages.croppingConfiguration.<table>.<recordType>.<fieldName>.variants.<variantName>.sizes`

Array of size names you can arbitrarily choose. Here you define how many different sizes you need of your image in the given variant.
Imagine you need to render the detail image of an article in 3 different sizes, but the list image only in one size because a responsive grid
in the frontend takes care of the images always being displayed in the same size on all devices, your structure could look like in this example:

```
package.Smichaelsen\MelonImages.croppingConfiguration.tx_news_domain_model_news._all.variants {
  list.sizes {
    unisize {
      # ...
    }
  }
  detail.sizes {
    small {
      # ...
    }
    medium {
      # ...
    }
    big {
      # ...
    }
  }
}
```

### 6.1 Size Configuration

`package.Smichaelsen\MelonImages.croppingConfiguration.<table>.<recordType>.<fieldName>.variants.<variantName>.sizes.<sizeName>`

`breakpoints` (optional): Comma separated list of breakpoint names (See *1. Breakpoints*) that this size is used for. If omitted the size will have no media query condition is used on all screens - recommended if you use only one size for the given variant. <br>
`width` (optional): Width in pixels the image is rendered in. <br>
`height` (optional): Height in pixels the image is rendered in. <br>
`ratio` (optional): If the ratio is given (in the x/y, e.g. 16/9) the width or the height may be omitted. The height can be calculated by width and ratio. The width can be calculated by height and ratio. If multiple sizes share the same ratios they are grouped in the backend so that the editor sets a single cropping for them. In the frontend they are still rendered as different sizes for different breakpoints.

If you provide `width` and `height` it results in a fixed aspect ratio that is enforced in the backend cropping tool. Neat!

### 6.1.1 Cover Areas

`package.Smichaelsen\MelonImages.croppingConfiguration.<table>.<recordType>.<fieldName>.variants.<variantName>.sizes.<sizeName>.coverAreas`

Numerical array of cover areas. See the [TCA Reference](https://docs.typo3.org/typo3cms/TCAReference/ColumnsConfig/Type/ImageManipulation.html#cropvariants) for details on that feature.

Each cover area needs has following properties:

`x:` Horizontal position of the upper left corner of the cover area from 0 to 1 (0 is the left, 1 the right edge of the image) <br>
`y:` Vertical Position of the upper left corner of the cover area from 0 to 1 (0 is the top, 1 the bottom edge of the image) <br>
`width` Width of the cover area from 0 to 1 (1 being 100% of the image width) <br>
`height` Height of the cover area from 0 to 1 (1 being 100% of the image height)

Example:

```
package.Smichaelsen\MelonImages.croppingConfiguration.tx_news_domain_model_news._all.variants {
  detail.sizes {
    big.coverAreas.0 {
      # Indicate to the editor that the right half of the image might be covered in the frontend
      x = 0.5
      y = 0
      width = 0.5
      height = 1
    }
  }
}
```

### 6.1.2 Focus Area

`package.Smichaelsen\MelonImages.croppingConfiguration.<table>.<recordType>.<fieldName>.variants.<variantName>.sizes.<sizeName>.focusArea`

See the [TCA Reference](https://docs.typo3.org/typo3cms/TCAReference/ColumnsConfig/Type/ImageManipulation.html#cropvariants) for details on that feature.

`x:` Horizontal position of the upper left corner of the initial focus area from 0 to 1 (0 is the left, 1 the right edge of the image) <br>
`y:` Vertical Position of the upper left corner of the initial focus area from 0 to 1 (0 is the top, 1 the bottom edge of the image) <br>
`width` Width of the initial focus area from 0 to 1 (1 being 100% of the image width) <br>
`height` Height of the initial focus area from 0 to 1 (1 being 100% of the image height) <br>

The position and dimensions of the focus area can be adjusted by the editor in the backend to mark the crucial area of the image.

Setting a focus area will have no effect on the backend image processing or its rendering with the `melon:responsivePicture` ViewHelper.
If you want to use this feature you need to take care of the frontend implementation.

Example:

```
package.Smichaelsen\MelonImages.croppingConfiguration.tx_news_domain_model_news._all.variants {
  detail.sizes {
    big.focusArea {
      # The initial focus area is a rectangle in the middle of the image
      x = 0.4
      y = 0.4
      width = 0.2
      height = 0.2
    }
  }
}
```

### 7. Inline Records (Nested Records)

`package.Smichaelsen\MelonImages.croppingConfiguration.<table>.<recordType>.<fieldName>.<subType>.<subField>`

If your field references other records that have image fields in them you can use this structure to configure.

Example: You have a "Contacts" content element, which a field field, that holds an arbitrary number of contact records.
Each contact has a square photo.

```
package.Smichaelsen\MelonImages.croppingConfiguration {
  # We want to target content elements of the type tx_myext_contacts
  tt_content.tx_myext_contacts {
    # Field "contacts" holds the relation to the contact records
    contacts {
      # The configuration is valid for any type of contact record
      _all {
        # Field "image" holds the photo of a contact
        image {
          # variant "list" with one size "unisize"
          variants.list.sizes.unisize {
            width = 200
            height = 200
          }
        }
      }
    }
  }
}
```

You can nest this configuration as deep as you need it to be.

## Breaking Changes

### From 1.x to 2.x

If you're using custom markup to output your image the width and height are now enclosed in a `\Smichaelsen\MelonImages\Domain\Dto\Dimensions` object.
In practice this means you will have to change `fallbackImageData.width` to `fallbackImageData.dimensions.width`.

### From 0.8 to 0.9

With upgrading you will lose all cropping information. You need to crop the images again the backend.
