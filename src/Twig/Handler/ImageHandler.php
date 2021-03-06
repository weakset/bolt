<?php

namespace Bolt\Twig\Handler;

use Bolt\Helpers\Image\Image;
use Bolt\Helpers\Image\Thumbnail;
use Bolt\Library as Lib;
use Bolt\Translation\Translator as Trans;
use Silex;

/**
 * Bolt specific Twig functions and filters that provide image support
 *
 * @internal
 */
class ImageHandler
{
    /** @var \Silex\Application */
    private $app;

    /**
     * @param \Silex\Application $app
     */
    public function __construct(Silex\Application $app)
    {
        $this->app = $app;
    }

    /**
     * Helper function to make a path to an image.
     *
     * @param string         $filename Target filename
     * @param string|integer $width    Target width
     * @param string|integer $height   Target height
     * @param string         $crop     String identifier for cropped images
     *
     * @return string Image path
     */
    public function image($filename, $width = null, $height = null, $crop = null)
    {
        if ($width || $height) {
            // You don't want the image, you just want a thumbnail.
            return $this->thumbnail($filename, $width, $height, $crop);
        }

        // After v1.5.1 we store image data as an array
        if (is_array($filename)) {
            $filename = isset($filename['filename']) ? $filename['filename'] : $filename['file'];
        }

        $image = sprintf(
            '%s%s',
            $this->app['resources']->getUrl('files'),
            Lib::safeFilename($filename)
        );

        return $image;
    }

    /**
     * Get an array with the dimensions of an image, together with its
     * aspectratio and some other info.
     *
     * @param string  $filename
     * @param boolean $safe
     *
     * @return array Specifics
     */
    public function imageInfo($filename, $safe)
    {
        // This function is vulnerable to path traversal, so blocking it in
        // safe mode for now.
        if ($safe) {
            return null;
        }

        return new Image(
            $filename,
            $this->app['resources']->getPath('filespath'),
            $this->app['resources']->getUrl('files')
        );
    }

    /**
     * Helper function to wrap an image in a Magnific popup HTML tag, with thumbnail.
     *
     * example: {{ content.image|popup(320, 240) }}
     * example: {{ popup(content.image, 320, 240) }}
     * example: {{ content.image|popup(width=320, height=240, title="My Image") }}
     *
     * Note: This function used to be called 'fancybox', but Fancybox was
     * deprecated in favour of the Magnific Popup library.
     *
     * @param string|array $fileName Image file name
     * @param integer      $width    Image width
     * @param integer      $height   Image height
     * @param string       $crop     Crop image string identifier
     * @param string       $title    Display title for image
     *
     * @return string HTML output
     */
    public function popup($fileName = null, $width = 100, $height = 100, $crop = null, $title = null)
    {
        if ($fileName === null) {
            return '&nbsp;';
        }

        $thumbconf = $this->app['config']->get('general/thumbnails');
        $fullwidth = !empty($thumbconf['default_image'][0]) ? $thumbconf['default_image'][0] : 1000;
        $fullheight = !empty($thumbconf['default_image'][1]) ? $thumbconf['default_image'][1] : 800;

        $thumb = $this->getThumbnail($fileName, $width, $height, $crop);
        $largeThumb = $this->getThumbnail($fileName, $fullwidth, $fullheight, 'r');

        // BC Nightmare… If we're passed a title, use it, if not we might have
        // one in the $fileName array, else use the file name
        $title = $title ?: $thumb->getTitle() ?: sprintf('%s: %s', Trans::__('Image'), $thumb->getFileName());
        $altTitle = $thumb->getAltTitle() ?: $title;

        $output = sprintf(
            '<a href="%s" class="magnific" title="%s"><img src="%s" width="%s" height="%s" alt="%s"></a>',
            $this->getThubnailUri($largeThumb),
            $title,
            $this->getThubnailUri($thumb),
            $thumb->getWidth(),
            $thumb->getHeight(),
            $altTitle
        );

        return $output;
    }

    /**
     * Helper function to show an image on a rendered page.
     *
     * Set width or height parameter to '0' for proportional scaling.
     * Set them both to '0' to get original width and height.
     *
     * Example: {{ content.image|showimage(320, 240) }}
     * Example: {{ showimage(content.image, 320, 240) }}
     *
     * @param string  $fileName Image filename
     * @param integer $width    Image width
     * @param integer $height   Image height
     * @param string  $crop     Crop image string identifier
     *
     * @return string HTML output
     */
    public function showImage($fileName = null, $width = null, $height = null, $crop = null)
    {
        if ($fileName === null) {
            return '&nbsp;';
        }
        $thumb = $this->getThumbnail($fileName, $width, $height, $crop);

        if ($width === null || $height === null) {
            $info = $this->imageInfo($thumb->getFileName(), false);

            if ($width !== null) {
                $thumb->setHeight(round($width / $info['aspectratio']));
            } elseif ($height !== null) {
                $thumb->setWidth(round($height * $info['aspectratio']));
            } else {
                $thumb->setWidth($info['width']);
                $thumb->setHeight($info['height']);
            }
        }

        return sprintf(
            '<img src="%s" width="%s" height="%s" alt="%s">',
            $this->getThubnailUri($thumb),
            $thumb->getWidth(),
            $thumb->getHeight(),
            $thumb->getAltTitle()
        );
    }

    /**
     * Helper function to make a path to an image thumbnail.
     *
     * @param string     $fileName Target filename
     * @param string|int $width    Target width
     * @param string|int $height   Target height
     * @param string     $zoomcrop Zooming and cropping: Set to 'f(it)', 'b(orders)', 'r(esize)' or 'c(rop)'
     *                             Set width or height parameter to '0' for proportional scaling
     *                             Setting them to '' uses default values.
     *
     * @return string Relative URL of the thumbnail
     */
    public function thumbnail($fileName, $width = null, $height = null, $zoomcrop = null)
    {
        $thumb = $this->getThumbnail($fileName, $width, $height, $zoomcrop);

        return $this->getThubnailUri($thumb);
    }

    /**
     * Get a thumbnail object.
     *
     * @param string|array $fileName
     * @param integer      $width
     * @param integer      $height
     * @param string       $scale
     *
     * @return Thumbnail
     */
    private function getThumbnail($fileName, $width = null, $height = null, $scale = null)
    {
        $thumb = new Thumbnail($this->app['config']->get('general/thumbnails'));
        $thumb
            ->setFileName($fileName)
            ->setWidth($width)
            ->setHeight($height)
            ->setScale($scale);

        return $thumb;
    }

    /**
     * Get the thumbnail relative URI.
     *
     * @param Thumbnail $thumb
     *
     * @return string
     */
    private function getThubnailUri(Thumbnail $thumb)
    {
        $thumbStr = sprintf(
            '%sx%s%s/%s',
            $thumb->getWidth(),
            $thumb->getHeight(),
            $thumb->getScale(),
            $thumb->getFileName()
        );

        return $this->app['url_generator']->generate('thumb', ['thumb' => $thumbStr]);
    }
}
