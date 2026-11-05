<?php

namespace App\Ai\Providers;

/** A native write filter works with both Guzzle's live sink and Laravel's HTTP fakes. */
final class CallbackSink extends \php_user_filter
{
    /** @return resource */
    public static function open(callable $onBytes)
    {
        if (! in_array('ai.chunks', stream_get_filters(), true)) {
            stream_filter_register('ai.chunks', self::class);
        }
        $stream = fopen('php://temp/maxmemory:262144', 'w+');
        stream_filter_append($stream, 'ai.chunks', STREAM_FILTER_WRITE, ['callback' => $onBytes]);

        return $stream;
    }

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            ($this->params['callback'])($bucket->data);
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
