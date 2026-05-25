<?php

namespace TCG\Voyager\Http\Controllers\ContentTypes;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image as InterventionImage;

class MultipleImage extends BaseType
{
    /**
     * @return string
     */
    public function handle()
    {
        $filesPath = [];
        $files = $this->request->file($this->row->field);

        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            if (!$file->isValid()) {
                continue;
            }

            $image = InterventionImage::read($file)->orient();

            $resize_width = null;
            $resize_height = null;

            if (isset($this->options->resize) && (
                isset($this->options->resize->width) || isset($this->options->resize->height)
            )) {
                if (isset($this->options->resize->width)) {
                    $resize_width = $this->options->resize->width;
                }
                if (isset($this->options->resize->height)) {
                    $resize_height = $this->options->resize->height;
                }
            } else {
                $resize_width = $image->width();
                $resize_height = $image->height();
            }

            $resize_quality = intval($this->options->quality ?? 75);
            $noUpsize = isset($this->options->upsize) && !$this->options->upsize;

            $filename = Str::random(20);
            $path = $this->slug.DIRECTORY_SEPARATOR.date('FY').DIRECTORY_SEPARATOR;
            array_push($filesPath, $path.$filename.'.'.$file->getClientOriginalExtension());
            $filePath = $path.$filename.'.'.$file->getClientOriginalExtension();

            if ($noUpsize) {
                $image->scaleDown($resize_width, $resize_height);
            } else {
                $image->scale($resize_width, $resize_height);
            }

            $ext = $file->getClientOriginalExtension();
            $encoded = $image->encodeByExtension($ext, quality: $resize_quality);

            Storage::disk(config('voyager.storage.disk'))->put($filePath, $encoded->toString(), 'public');

            if (isset($this->options->thumbnails)) {
                foreach ($this->options->thumbnails as $thumbnails) {
                    $thumbEncoded = null;

                    if (isset($thumbnails->name) && isset($thumbnails->scale)) {
                        $scale = intval($thumbnails->scale) / 100;
                        $thumb_resize_width = $resize_width;
                        $thumb_resize_height = $resize_height;

                        if ($thumb_resize_width != null && $thumb_resize_width != 'null') {
                            $thumb_resize_width = intval($thumb_resize_width * $scale);
                        }

                        if ($thumb_resize_height != null && $thumb_resize_height != 'null') {
                            $thumb_resize_height = intval($thumb_resize_height * $scale);
                        }

                        $thumbImage = InterventionImage::read($file)->orient();
                        if ($noUpsize) {
                            $thumbImage->scaleDown($thumb_resize_width, $thumb_resize_height);
                        } else {
                            $thumbImage->scale($thumb_resize_width, $thumb_resize_height);
                        }
                        $thumbEncoded = $thumbImage->encodeByExtension($ext, quality: $resize_quality);
                    } elseif (isset($this->options->thumbnails) && isset($thumbnails->crop->width) && isset($thumbnails->crop->height)) {
                        $crop_width = $thumbnails->crop->width;
                        $crop_height = $thumbnails->crop->height;
                        $thumbEncoded = InterventionImage::read($file)
                            ->orient()
                            ->cover($crop_width, $crop_height)
                            ->encodeByExtension($ext, quality: $resize_quality);
                    }

                    if ($thumbEncoded !== null) {
                        Storage::disk(config('voyager.storage.disk'))->put(
                            $path.$filename.'-'.$thumbnails->name.'.'.$ext,
                            $thumbEncoded->toString(),
                            'public'
                        );
                    }
                }
            }
        }

        return json_encode($filesPath);
    }
}
