# Melon Images
**Responsive Images Management for TYPO3**

This package uses the powerful responsive image cropping capabilities of TYPO3 and provides easy frontend rendering.

![Image Cropping](doc/image-cropping.png?raw=true "Image Cropping")

TYPO3 8.7 comes with the powerful feature of `cropVariant`s, which lets you define use cases for your image including `allowedAspectRatios` and optionally `coverAreas`.
This package simplifies the configuration and frontend rendering of this feature.

## Configuration:

The configuration happens completely in TypoScript. This example configures 3 **variants** of the `tx_news_domain_model_news.fal_media` field,
that are *detail*, *featured* and *teaser*. The use case is we want to use the same image in 3 different views with different cropping. Each
variant can also have different **sizes**. The *detail* variant for example is available in the sizes *big* (for tablet and desktop
viewport sizes) and *phone*. 

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
                  title = Detail Tablet & Desktop
                  breakpoints = tablet,desktop
                  width = 943
                  height = 419
                }

                phone {
                  # the title is omitted here and defaults to "Detail Phone" (derived from the name of the variant and the size)
                  breakpoints = phone
                  width = 480
                  height = 320
                }
              }
            }

            featured {
              sizes {
                big {
                  breakpoints = tablet,desktop
                  width = 748
                  height = 420
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
          }
        }
      }
    }
  }
}

```

## Rendering

To render the reponsive image with the correct cropping use the provided ViewHelper:

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
