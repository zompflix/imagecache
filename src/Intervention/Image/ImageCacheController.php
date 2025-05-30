<?php

namespace Intervention\Image;

use Closure;
use Config;
use Intervention\Image\Facades\Image;
use Intervention\Image\ImageManager;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Response as IlluminateResponse;

class ImageCacheController extends BaseController
{


    /**
     * @var null|Closure
     */
    protected $defaultImagePath = null;


    protected static $dynamicCachePaths = [];

    /**
     * Get HTTP response of either original image file or
     * template applied file.
     *
     * @param  string $template
     * @param  string $filename
     * @return Illuminate\Http\Response
     */
    public function getResponse($template, $filename)
    {
        switch (strtolower($template)) {
            case 'original':
                return $this->getOriginal($filename);

            case 'download':
                return $this->getDownload($filename);

            default:
                return $this->getImage($template, $filename);
        }
    }

    /**
     * Get HTTP response of template applied image file
     *
     * @param  string $template
     * @param  string $filename
     * @return Illuminate\Http\Response
     */
    public function getImage($template, $filename)
    {
        $template = $this->getTemplate($template);
        $path = $this->getImagePath($filename);

        // image manipulation based on callback
        $manager = new ImageManager(Config::get('image'));
        $content = $manager->cache(function ($image) use ($template, $path) {

            if ($template instanceof Closure) {
                // build from closure callback template
                $template($image->make($path));
            } else {
                // build from filter template
                $image->make($path)->filter($template);
            }
        }, config('imagecache.lifetime'));

        return $this->buildResponse($content);
    }

    /**
     * Get HTTP response of original image file
     *
     * @param  string $filename
     * @return Illuminate\Http\Response
     */
    public function getOriginal($filename)
    {
        $path = $this->getImagePath($filename);

        return $this->buildResponse(file_get_contents($path));
    }

    /**
     * Get HTTP response of original image as download
     *
     * @param  string $filename
     * @return Illuminate\Http\Response
     */
    public function getDownload($filename)
    {
        $response = $this->getOriginal($filename);

        return $response->header(
            'Content-Disposition',
            'attachment; filename=' . $filename
        );
    }

    /**
     * Returns corresponding template object from given template name
     *
     * @param  string $template
     * @return mixed
     */
    protected function getTemplate($template)
    {
        $template = config("imagecache.templates.{$template}");

        switch (true) {
            // closure template found
            case is_callable($template):
                return $template;

            // filter template found
            case class_exists($template):
                return new $template();

            default:
                // template not found
                abort(404);
                break;
        }
    }

    /**
     * Returns full image path from given filename
     *
     * @param  string $filename
     * @return string
     */
    protected function getImagePath($filename)
    {
        // find file
        $dynamicPaths = static::getDynamicCachePaths();
        $paths = array_merge(config('imagecache.paths',[]),$dynamicPaths);
        foreach ($paths as $path) {
            // don't allow '..' in filenames
            $image_path = $path . '/' . str_replace('..', '', $filename);
            if (file_exists($image_path) && is_file($image_path)) {
                // file found
                return $image_path;
            }
        }

        $image_path = $this->getDefaultImagePath();

        if ($image_path !== false) {
            return $image_path;
        }

        // file not found
        abort(404);
    }

    /**
     * Builds HTTP response from given image data
     *
     * @param  string $content
     * @return Illuminate\Http\Response
     */
    protected function buildResponse($content)
    {
        // define mime type
        $mime = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $content);

        // respond with 304 not modified if browser has the image cached
        $etag = md5($content);
        $not_modified = isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] == $etag;
        $content = $not_modified ? null : $content;
        $status_code = $not_modified ? 304 : 200;

        // return http response
        return new IlluminateResponse($content, $status_code, [
            'Content-Type' => $mime,
            'Cache-Control' => 'max-age=' . (config('imagecache.lifetime') * 60) . ', public',
            'Content-Length' => strlen($content),
            'Etag' => $etag
        ]);
    }


    public function getDefaultImagePath() {
        if (is_null($this->defaultImagePath)) {
            return config('image.default_path',public_path('default_image.png'));
        }

        return ($this->defaultImagePath)();
    }

    /**
     * @param Closure|null $defaultImagePath
     */
    public function setDefaultImagePath(Closure $defaultImagePath)
    {
        $this->defaultImagePath = $defaultImagePath;
    }


    public static function setDynamicPaths(array $paths) {
        static::$dynamicCachePaths = $paths;
    }

    public static function addDynamicPaths(array $paths) {
        static::$dynamicCachePaths = array_merge(static::$dynamicCachePaths,$paths);
    }

    /**
     * @return array
     */
    public static function getDynamicCachePaths()
    {
        return static::$dynamicCachePaths;
    }

}
