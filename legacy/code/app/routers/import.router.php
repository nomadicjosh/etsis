<?php
if (!defined('BASE_PATH'))
    exit('No direct script access allowed');

/**
 * Before route check.
 */
$app->before('GET|POST', '/form/import/', function() {
    if (!hasPermission('import_data')) {
        _etsis_flash()->error(_t('403 - Error: Forbidden.'), get_base_url() . 'dashboard' . '/');
    }
});

$app->match('GET|POST', '/form/import/', function () use($app) {

    if ($app->req->isPost()) {
        if ($_FILES['file_source']['type'] == 'text/csv' && $_FILES['file_source']['size'] > 0) {
            $csv = new \app\src\CSVimporter($_FILES['file_source']['name'], $_FILES['file_source']['tmp_name']);
            if ($csv->queryInto($app->req->post['table'])) {
                _etsis_flash()->success(_t('200 - Ok: Data was imported successfully.'), $app->req->server['HTTP_REFERER']);
            } else {
                _etsis_flash()->error(_t('409 - Conflict: There was an error with saving the data to the database.'), $app->req->server['HTTP_REFERER']);
            }
        } else {
            _etsis_flash()->error(_t('204 - No Content: The file was neither in csv format or the file was empty.'), $app->req->server['HTTP_REFERER']);
        }
    }

    etsis_register_style('form');
    etsis_register_script('select');
    etsis_register_script('select2');
    etsis_register_script('upload');

    $app->view->display('form/import', [
        'title' => _t('Importer')
        ]
    );
});

$app->setError(function() use($app) {

    $app->view->display('error/404', ['title' => '404 Error']);
});
