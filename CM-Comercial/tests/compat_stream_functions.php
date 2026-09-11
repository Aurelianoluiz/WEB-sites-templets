<?php
declare(strict_types=1);

if (!function_exists('stream_get_name')) {
    function stream_get_name($stream, bool $wantPeer = false): string|false
    {
        if (!is_resource($stream)) {
            return false;
        }
        return stream_socket_get_name($stream, $wantPeer);
    }
}
