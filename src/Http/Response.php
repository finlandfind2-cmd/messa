<?php
declare(strict_types=1);
namespace Messa\Http;

final class Response
{
    private int $status = 200;
    private array $headers = ['Content-Type' => 'application/json; charset=utf-8'];
    private string $body = '';

    public function status(int $code): self { $this->status = $code; return $this; }
    public function setStatus(int $code): self { return $this->status($code); }
    public function header(string $name, string $value): self { $this->headers[$name] = $value; return $this; }
    public function body(string $content): self { $this->body = $content; return $this; }

    public function json(array $data): self
    {
        $this->headers['Content-Type'] = 'application/json; charset=utf-8';
        $this->body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        header('Content-Length: ' . strlen($this->body));
        echo $this->body;
    }
}
