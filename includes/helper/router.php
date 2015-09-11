<?php

$routes = array(
    'api' => array('require' => realpath(__DIR__ . '/../controller/api.php'))
);

/**
 * @param $pageShortCut
 *
 * @return array
 */
function matchPageShortcut($pageShortCut)
{

    $title = $pageShortCut;
    $content = "";

    if (isset($routes[$pageShortCut])) {
        $route = $routes[$pageShortCut];

        if (isset($route['require'])) {
            require_once realpath(__DIR__ . $route['require']);
        }

        if (isset($route['error'])) {
            error($route['error']);
        }

        if (isset($route['call'])) {
            $route['call']();
        } else {
            $title = $route['title']();
            $content = $route['content']();
        }
    }

    return array($title, $content);
}
