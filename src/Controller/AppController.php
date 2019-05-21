<?php

declare(strict_types=1);

namespace App\Controller;

use App\Serializer\XmlSerializer;
use App\Service\CacheService;
use App\Service\Ratp\RatpServiceInterface;

use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Exception\InvalidParameterException;
use FOS\RestBundle\View\View;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

class AppController extends AbstractFOSRestController
{
    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @param RequestStack $requestStack
     */
    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    /**
     * @Route("/")
     *
     * @return RedirectResponse
     */
    public function index(): RedirectResponse
    {
        return new RedirectResponse('documentation');
    }

    /**
     * @param array $payload
     * @param int $httpCode
     *
     * @return View
     */
    public function appView(array $payload, int $httpCode = Response::HTTP_OK): View
    {
        $acceptHeader = $this->requestStack->getCurrentRequest()->headers->get('Accept');
        $format       = $acceptHeader == 'application/xml' ? 'xml' : 'json';

        if ($format === 'xml') {
            $result = new XmlSerializer();
            $result->setResult($payload);
            $result->setMetadata($this->getMetadata());
        } else {
            $result = [
                'result'    => $payload,
                '_metadata' => $this->getMetadata()
            ];
        }

        $view = View::create($result);
        $view->setFormat($format);
        $view->setStatusCode($httpCode);

        return $view;
    }

    /**
     * @return array
     */
    private function getMetadata(): array
    {
        return [
            'call'    => $this->getCall(),
            'date'    => date('c'),
            'version' => getenv('API_VERSION')
        ];
    }

    /**
     * @param RatpServiceInterface $service
     * @param string $method
     * @param int $ttl
     * @param string $hash
     *
     * @return array
     */
    protected function fetchData(
        RatpServiceInterface $service,
        string $method = '',
        int $ttl = 0,
        string $hash = ''
    ): array
    {
        $cacheService = new CacheService($this->requestStack, $hash);
        $data         = $cacheService->getDataFromCache();

        if (!$data) {
            $data = $service->get($method);
            $cacheService->setDataToCache($data, $ttl);
        }

        return $data;
    }

    /**
     * @return string
     */
    private function getCall(): string
    {
        $method = $this->requestStack->getCurrentRequest()->getMethod();
        $path   = $this->requestStack->getCurrentRequest()->getPathInfo();

        return $method . ' ' . $path;
    }

    /**
     * @param \Exception $exception
     *
     * @return View
     */
    public function errorView(\Exception $exception)
    {
        $exceptionClass = get_class($exception);

        switch ($exceptionClass) {
            case NotFoundHttpException::class:
                $responseCode   = Response::HTTP_NOT_FOUND;
                $responseStatus = 'Not found. ' . $exception->getMessage();
                break;
            case InvalidParameterException::class:
                $responseCode   = Response::HTTP_BAD_REQUEST;
                $responseStatus = 'Bad request. ' . $exception->getMessage();
                break;
            default:
                $responseCode   = Response::HTTP_INTERNAL_SERVER_ERROR;
                $responseStatus = 'Internal Server Error' .
                    (getenv('APP_ENV') === 'dev' ? ' (' . $exception->getMessage() . ')' : '');
                break;
        }

        $payload = [
            'code'    => $responseCode,
            'message' => $responseStatus
        ];

        return $this->appView($payload, $responseCode);
    }
}
