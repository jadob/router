<?php

namespace Jadob\Router;

use Jadob\Router\Exception\MethodNotAllowedException;
use Jadob\Router\Exception\RouteNotFoundException;
use Jadob\Router\Exception\RouterException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class Router
 * Service name: router
 * @package Jadob\Router
 * @author pizzaminded <miki@appvende.net>
 * @license MIT
 */
class Router
{

    /**
     * @var array
     */
    protected $config;

    /**
     * @var RouteCollection
     */
    protected $routeCollection;

    /**
     * @var string
     */
    protected $leftDelimiter = '{';

    /**
     * @var string
     */
    protected $rightDelimiter = '}';

    /**
     * @var Route
     */
    protected $currentRoute;

    /**
     * @deprecated
     * @var array
     */
    protected $globalParams = [];

    /**
     * @var Context
     */
    protected $context;

    /**
     * @param RouteCollection $routeCollection
     * @param Context|null $context
     */
    public function __construct(RouteCollection $routeCollection, ?Context $context = null)
    {
        $this->routeCollection = $routeCollection;

        $this->config = [
            'case_sensitive' => false
        ];

        if ($context !== null) {
            $this->context = $context;
        } else {
            $this->context = Context::fromGlobals();
        }
    }

    /**
     * @param Route $route
     * @param $host
     * @return bool
     */
    protected function hostMatches(Route $route, $host): bool
    {
        if ($route->getHost() === null) {
            return true;
        }

        return $route->getHost() === $host;
    }


    /**
     * @param string $path
     * @param string $method
     * @return Route
     * @throws MethodNotAllowedException
     * @throws RouteNotFoundException
     */
    public function matchRoute(string $path, string $method): Route
    {
        $method = \strtoupper($method);

        foreach ($this->routeCollection as $routeKey => $route) {
            /** @var Route $route */
            $pathRegex = $this->getRegex($route->getPath());
            //@TODO: maybe we should break here if $pathRegex === false?

            if ($pathRegex !== false
                && preg_match($pathRegex, $path, $matches) > 0
                && $this->hostMatches($route, $this->context->getHost())
            ) {

                if (
                    count(($routeMethods = $route->getMethods())) > 0
                    && !\in_array($method, $routeMethods)
                ) {
                    throw new MethodNotAllowedException();
                }

                $parameters = array_intersect_key(
                    $matches, array_flip(array_filter(array_keys($matches), 'is_string'))
                );

                $route->setParams($parameters);

                return $route;
            }

        }

        throw new RouteNotFoundException('No route matched for URI ' . $path);
    }

    /**
     * @param Request $request
     * @return Route
     * @throws RouteNotFoundException
     * @throws MethodNotAllowedException
     */
    public function matchRequest(Request $request): Route
    {
        return $this->matchRoute(
            $request->getPathInfo(),
            $request->getMethod()
        );
    }

    /**
     * @param $pattern
     * @return bool|string
     */
    protected function getRegex($pattern)
    {
        if (preg_match('/[^-:.\/_{}()a-zA-Z\d]/', $pattern)) {
            return false; // Invalid pattern
        }

        $allowedParamChars = '[a-zA-Z0-9\.\_\-]+';
        // Create capture group for '{parameter}'
        $parsedPattern = preg_replace(
            '/{(' . $allowedParamChars . ')}/', # Replace "{parameter}"
            '(?<$1>' . $allowedParamChars . ')', # with "(?<parameter>[a-zA-Z0-9\_\-]+)"
            $pattern
        );

        // Add start and end matching
        $patternAsRegex = '%^' . $parsedPattern . '$%D';

        if (!$this->config['case_sensitive']) {
            $patternAsRegex .= 'i';
        }

        return $patternAsRegex;
    }

    /**
     * @param $name
     * @param $params
     * @param bool $full
     * @return mixed|string
     * @throws RouteNotFoundException
     */
    public function generateRoute($name, array $params = [], $full = false)
    {

        foreach ($this->routeCollection as $routeName => $route) {
            if ($routeName === $name) {
                if (isset($this->config['locale_prefix']) && !$route->isIgnoreGlobalPrefix()) {
                    $path = $this->config['locale_prefix'] . $route->getPath();
                    $params = array_merge($params, $this->globalParams);

                } else {
                    $path = $route->getPath();
                }

                $paramsToGET = [];

                $convertedPath = $path;
                foreach ($params as $key => $param) {

                    $isFound = 0;
                    if (!\is_array($param)) {
                        $convertedPath = str_replace('{' . $key . '}', $param, $convertedPath, $isFound);
                    };

                    if ($isFound === 0) {
                        $paramsToGET[$key] = $param;
                    }
                }

                if (\count($paramsToGET) !== 0) {
                    $convertedPath .= '?';
                    $convertedPath .= http_build_query($paramsToGET);
                }

                if ($full) {
                    return $this->context->getSchemeAndHttpHost() . $convertedPath;
                }
                return $convertedPath;
            }
        }

        throw new RouteNotFoundException('Route "' . $name . '" is not defined');

    }

    /**
     * @return Route
     */
    public function getCurrentRoute(): Route
    {
        return $this->currentRoute;
    }

    /**
     * @param Route $currentRoute
     * @return Router
     */
    public function setCurrentRoute(Route $currentRoute): Router
    {
        $this->currentRoute = $currentRoute;

        return $this;
    }

    /**
     * @deprecated
     * @return array
     */
    public function getGlobalParams()
    {
        return $this->globalParams;
    }

    /**
     * @deprecated
     * @return string
     */
    public function getGlobalParam($key)
    {
        return $this->globalParams[$key];
    }

    /**
     * @deprecated
     * @param array $globalParams
     * @return Router
     */
    public function setGlobalParams(array $globalParams)
    {
        $this->globalParams = $globalParams;

        return $this;
    }

    /**
     * @return Context
     */
    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * @param Context $context
     * @return Router
     */
    public function setContext(Context $context): Router
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Allows to set custom route argument delimiters
     * @param string $left
     * @param string $right
     * @return $this
     */
    public function setParameterDelimiters(string $left, string $right) {
        $this->leftDelimiter = $left;
        $this->rightDelimiter = $right;

        return $this;
    }

    /**
     * @return RouteCollection
     */
    public function getRouteCollection(): RouteCollection
    {
        return $this->routeCollection;
    }
}

