<?php

declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\AdminBundle\Controller\Admin;

use Pimcore\Bundle\AdminBundle\Controller\AdminController;
use Pimcore\Bundle\AdminBundle\Helper\QueryParams;
use Pimcore\Bundle\AdminBundle\HttpFoundation\JsonResponse;
use Pimcore\Logger;
use Pimcore\Model\Document;
use Pimcore\Model\Redirect;
use Pimcore\Model\Site;
use Pimcore\Routing\Redirect\Csv;
use Pimcore\Routing\RedirectHandler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/redirects")
 *
 * @internal
 */
class RedirectsController extends AdminController
{
    /**
     * @Route("/list", name="pimcore_admin_redirects_redirects", methods={"POST"})
     *
     * @param Request $request
     * @param RedirectHandler $redirectHandler
     *
     * @return JsonResponse
     */
    public function redirectsAction(Request $request, RedirectHandler $redirectHandler): JsonResponse
    {
        // check permission for both update and listing
        $this->checkPermission('redirects');

        if ($request->get('data')) {
            if ($request->get('xaction') === 'destroy') {
                $data = $this->decodeJson($request->get('data'));

                $id = $data['id'] ?? null;
                if ($id) {
                    $redirect = Redirect::getById($id);
                    $redirect?->delete();
                }

                return $this->adminJson(['success' => true, 'data' => []]);
            }
            if ($request->get('xaction') === 'update') {
                $data = $this->decodeJson($request->get('data'));

                // save redirect
                $redirect = Redirect::getById($data['id']);

                if (!$redirect) {
                    return $this->adminJson(['success' => false]);
                }

                if ($data['target']) {
                    if ($doc = Document::getByPath($data['target'])) {
                        $data['target'] = $doc->getId();
                    }
                }

                if (!$data['regex'] && $data['source']) {
                    $data['source'] = str_replace('+', ' ', $data['source']);
                }

                $redirect->setValues($data);

                $redirect->save();

                $redirectTarget = $redirect->getTarget();
                if (is_numeric($redirectTarget)) {
                    if ($doc = Document::getById((int)$redirectTarget)) {
                        $redirect->setTarget($doc->getRealFullPath());
                    }
                }

                return $this->adminJson(['data' => $redirect->getObjectVars(), 'success' => true]);
            }
            if ($request->get('xaction') === 'create') {
                $data = $this->decodeJson($request->get('data'));
                unset($data['id']);

                // save route
                $redirect = new Redirect();

                if (!empty($data['target'])) {
                    if ($doc = Document::getByPath($data['target'])) {
                        $data['target'] = $doc->getId();
                    }
                }

                if (isset($data['regex']) && !$data['regex'] && isset($data['source']) && $data['source']) {
                    $data['source'] = str_replace('+', ' ', $data['source']);
                }

                $redirect->setValues($data);

                $redirect->save();

                $redirectTarget = $redirect->getTarget();
                if (is_numeric($redirectTarget)) {
                    if ($doc = Document::getById((int)$redirectTarget)) {
                        $redirect->setTarget($doc->getRealFullPath());
                    }
                }

                return $this->adminJson(['data' => $redirect->getObjectVars(), 'success' => true]);
            }
        } else {
            // get list of routes

            $list = new Redirect\Listing();
            $list->setLimit((int)$request->get('limit', 50));
            $list->setOffset((int)$request->get('start', 0));

            $sortingSettings = QueryParams::extractSortingSettings(array_merge($request->request->all(), $request->query->all()));
            if ($sortingSettings['orderKey']) {
                $list->setOrderKey($sortingSettings['orderKey']);
                $list->setOrder($sortingSettings['order']);
            }

            if ($filterValue = $request->get('filter')) {
                if (is_numeric($filterValue)) {
                    $list->setCondition('id = ?', [$filterValue]);
                } elseif (preg_match('@^https?://@', $filterValue)) {
                    $dummyRequest = Request::create($filterValue);
                    $site = Site::getByDomain($dummyRequest->getHost());
                    $dummyResponse = $redirectHandler->checkForRedirect($dummyRequest, false, $site);
                    if ($dummyResponse && $redirectId = $dummyResponse->headers->get(RedirectHandler::RESPONSE_HEADER_NAME_ID)) {
                        $list->setCondition('id = ?', [$redirectId]);
                    } else {
                        // do not return any results
                        $list->setCondition('1 = 2');
                    }
                } else {
                    $list->setCondition('`source` LIKE ' . $list->quote('%' . $filterValue . '%') . ' OR `target` LIKE ' . $list->quote('%' . $filterValue . '%'));
                }
            }

            $list->load();

            $redirects = [];
            foreach ($list->getRedirects() as $redirect) {
                if ($link = $redirect->getTarget()) {
                    if (is_numeric($link)) {
                        if ($doc = Document::getById((int)$link)) {
                            $redirect->setTarget($doc->getRealFullPath());
                        }
                    }
                }

                $redirects[] = $redirect->getObjectVars();
            }

            return $this->adminJson(['data' => $redirects, 'success' => true, 'total' => $list->getTotalCount()]);
        }

        return $this->adminJson(['success' => false]);
    }

    /**
     * @Route("/csv-export", name="pimcore_admin_redirects_csvexport", methods={"GET"})
     *
     * @param Csv $csv
     *
     * @return Response
     */
    public function csvExportAction(Csv $csv): Response
    {
        $this->checkPermission('redirects');

        $list = new Redirect\Listing();
        $list->setOrderKey('id');
        $list->setOrder('ASC');
        $list->load();

        $writer = $csv->createExportWriter($list);

        $response = new Response();
        $response->headers->set('Content-Encoding', 'none');
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'redirects.csv'
        ));

        $response->setContent($writer->toString());

        return $response;
    }

    /**
     * @Route("/csv-import", name="pimcore_admin_redirects_csvimport", methods={"POST"})
     *
     * @param Request $request
     * @param Csv $csv
     *
     * @return Response
     */
    public function csvImportAction(Request $request, Csv $csv): Response
    {
        $this->checkPermission('redirects');

        /** @var UploadedFile|null $file */
        $file = $request->files->get('redirects');

        if (!$file) {
            throw new BadRequestHttpException('Missing file');
        }

        $result = $csv->import($file->getRealPath());

        return $this->adminJson([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * @Route("/cleanup", name="pimcore_admin_redirects_cleanup", methods={"DELETE"})
     *
     * @return JsonResponse
     */
    public function cleanupAction(): JsonResponse
    {
        $this->checkPermission('redirects');

        try {
            $now = time();
            $expiredRedirects = new Redirect\Listing();
            $expiredRedirects->setCondition("expiry IS NOT NULL AND expiry < $now");
            $expiredRedirects = $expiredRedirects->load();

            foreach ($expiredRedirects as $expiredRedirect) {
                $expiredRedirect->delete();
            }

            return $this->adminJson(['success' => true]);
        } catch (\Exception $e) {
            Logger::error($e->getMessage());

            return $this->adminJson(['success' => false]);
        }
    }

    /**
     * @Route("/get-statuscodes", name="pimcore_admin_redirects_statuscodes", methods={"GET"})
     *
     * @return JsonResponse
     */
    public function statusCodesAction(): JsonResponse
    {
        $this->checkPermission('redirects');
        $statusCodes = Redirect::getStatusCodes();
        $codes = [];
        foreach ($statusCodes as $statusCode => $label) {
            $codes[] = [
                'statusCode' => $statusCode,
                'display' => "$label ($statusCode)",
            ];
        }
        $response = [
            'config' => [
                'statuscodes' => $codes,
            ],
        ];

        return $this->adminJson($response);
    }
}