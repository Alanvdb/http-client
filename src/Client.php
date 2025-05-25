<?php declare(strict_types=1);

namespace AlanVdb\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use AlanVdb\Http\Exception\ClientException;
use RuntimeException;

class Client implements ClientInterface
{
    protected array $curlGlobalOptions = [];
    protected array $globalHeaders = [];
    protected ?string $ssl = null;

    public function __construct(
        protected ResponseFactoryInterface $responseFactory
    ) {}

    /**
     * Sends a PSR-7 request and returns a PSR-7 response.
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws ClientException If an error happens while processing the request.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $ch = curl_init();

        // Set URL and method
        curl_setopt($ch, CURLOPT_URL, (string)$request->getUri());
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request->getMethod());

        // Set headers
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headers[] = "{$name}: {$value}";
            }
        }
        $headers = $this->applyGlobalHeaders($headers);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Set body
        $body = $request->getBody();
        if ($body->getSize() > 0) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$body);
        }

        // Apply SSL configuration
        if ($this->ssl) {
            curl_setopt($ch, CURLOPT_SSLCERT, $this->ssl);
        }

        // Apply global options
        foreach ($this->curlGlobalOptions as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        // Set default response options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Execute the request
        $responseBodyContent = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($responseBodyContent === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ClientException($error);
        }

        curl_close($ch);

        // Create the PSR-7 response
        $response = $this->responseFactory->createResponse($statusCode);
        $response->getBody()->write($responseBodyContent);

        return $response;
    }

    // SSL Configuration
    public function setSsl(string $cert): self
    {
        $this->ssl = (is_file($cert)) ? file_get_contents($cert) : $cert;
        return $this;
    }

    // Global cURL options management
    public function setGlobalCurlOption(int $option, mixed $value): self
    {
        $this->curlGlobalOptions[$option] = $value;
        return $this;
    }

    public function clearGlobalCurlOptions(): self
    {
        $this->curlGlobalOptions = [];
        return $this;
    }

    // Proxy configuration
    public function setProxy(string $proxy, ?string $username = null, ?string $password = null): self
    {
        $this->setGlobalCurlOption(CURLOPT_PROXY, $proxy);

        if ($username !== null && $password !== null) {
            $this->setGlobalCurlOption(CURLOPT_PROXYUSERPWD, "{$username}:{$password}");
        }

        return $this;
    }

    public function disableProxy(): self
    {
        unset($this->curlGlobalOptions[CURLOPT_PROXY]);
        unset($this->curlGlobalOptions[CURLOPT_PROXYUSERPWD]);
        return $this;
    }

    // SSL verification
    public function enableSslVerification(): self
    {
        $this->setGlobalCurlOption(CURLOPT_SSL_VERIFYPEER, true);
        $this->setGlobalCurlOption(CURLOPT_SSL_VERIFYHOST, 2);
        return $this;
    }

    public function disableSslVerification(): self
    {
        $this->setGlobalCurlOption(CURLOPT_SSL_VERIFYPEER, false);
        $this->setGlobalCurlOption(CURLOPT_SSL_VERIFYHOST, 0);
        return $this;
    }

    // Timeouts
    public function setTimeout(int $timeout): self
    {
        $this->setGlobalCurlOption(CURLOPT_TIMEOUT, $timeout);
        return $this;
    }

    public function setConnectTimeout(int $connectTimeout): self
    {
        $this->setGlobalCurlOption(CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        return $this;
    }

    // Cookie support
    public function enableCookieSupport(string $cookieFilePath): self
    {
        $this->setGlobalCurlOption(CURLOPT_COOKIEJAR, $cookieFilePath);
        $this->setGlobalCurlOption(CURLOPT_COOKIEFILE, $cookieFilePath);
        return $this;
    }

    public function disableCookieSupport(): self
    {
        unset($this->curlGlobalOptions[CURLOPT_COOKIEJAR]);
        unset($this->curlGlobalOptions[CURLOPT_COOKIEFILE]);
        return $this;
    }

    // User-Agent
    public function setUserAgent(string $userAgent): self
    {
        $this->setGlobalCurlOption(CURLOPT_USERAGENT, $userAgent);
        return $this;
    }

    public function resetUserAgent(): self
    {
        unset($this->curlGlobalOptions[CURLOPT_USERAGENT]);
        return $this;
    }

    // Global headers
    public function addGlobalHeader(string $name, string $value): self
    {
        $this->globalHeaders[$name] = $value;
        return $this;
    }

    public function removeGlobalHeader(string $name): self
    {
        unset($this->globalHeaders[$name]);
        return $this;
    }

    protected function applyGlobalHeaders(array $headers): array
    {
        foreach ($this->globalHeaders as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }
        return $headers;
    }

    // Save response to a file
    public function saveResponseToFile(string $filePath): self
    {
        $this->setGlobalCurlOption(CURLOPT_FILE, fopen($filePath, 'w'));
        return $this;
    }
}
