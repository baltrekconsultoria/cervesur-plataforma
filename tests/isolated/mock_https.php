<?php
// Test-only replacement of HTTPS: the isolated container never calls Microsoft.
stream_wrapper_unregister('https');
class FixtureHttps {
    public $context;
    private $position = 0;
    private $body = '{"access_token":"synthetic-provider-token"}';
    public function stream_open($path, $mode, $options, &$opened_path) { return true; }
    public function stream_read($count) { $part = substr($this->body, $this->position, $count); $this->position += strlen($part); return $part; }
    public function stream_eof() { return $this->position >= strlen($this->body); }
    public function stream_stat() { return []; }
}
stream_wrapper_register('https', FixtureHttps::class);
