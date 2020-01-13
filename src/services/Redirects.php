<?php
/**
 *
 * @author    dolphiq & Venveo
 * @copyright Copyright (c) 2017 dolphiq
 * @copyright Copyright (c) 2019 Venveo
 */

namespace venveo\redirect\services;

use Craft;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use venveo\redirect\elements\db\RedirectQuery;
use venveo\redirect\elements\Redirect;
use venveo\redirect\Plugin;
use venveo\redirect\records\Redirect as RedirectRecord;
use yii\base\Component;
use yii\base\ExitException;
use yii\web\HttpException;

/**
 * Class Redirects service.
 *
 */
class Redirects extends Component
{
    /**
     * Returns a redirect by its ID.
     *
     * @param int $redirectId
     * @param int|null $siteId
     *
     * @return Redirect|null
     */
    public function getRedirectById(int $redirectId, int $siteId = null)
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return Craft::$app->getElements()->getElementById($redirectId, Redirect::class, $siteId);
    }


    /**
     * Processes a 404 event, checking for redirects
     * @param HttpException $exception
     * @throws \yii\base\InvalidConfigException
     */
    public function handle404(HttpException $exception)
    {
        // Path with query params
        $fullPath = Craft::$app->request->getFullPath();

        $query = new RedirectQuery(Redirect::class);
        $query->matchingUri = $fullPath;
        $matchedRedirects = $query->all();
        if (empty($matchedRedirects)) {
            if (Plugin::$plugin->getSettings()->catchAllActive) {
                $this->register404();
            }
            return;
        }

        // Make sure we handle static redirects first
        usort($matchedRedirects, function ($a, $b) {
            if ($a->type === 'static' && $b->type === 'dynamic') {
                return -1;
            }

            if ($a->type === 'dynamic' && $b->type === 'static') {
                return 1;
            }

            return 0;
        });

        try {
            $this->doRedirect($matchedRedirects[0], $fullPath);
        } catch (\Exception $e) {
            return;
        }
    }

    /**
     * Performs the actual redirect
     *
     * @param Redirect $redirect
     * @param $uri
     * @throws \Exception
     */
    public function doRedirect(Redirect $redirect, $uri)
    {
        $destinationUrl = null;
        if ($redirect->type === Redirect::TYPE_STATIC) {
            $processedUrl = $redirect->destinationUrl;
        } elseif ($redirect->type === Redirect::TYPE_DYNAMIC) {
            $sourceUrl = $redirect->sourceUrl;
            // Add leading and trailing slashes for RegEx
            if (mb_strpos($sourceUrl, '/') !== 0) {
                $sourceUrl = '/' . $sourceUrl;
            }
            if (mb_strrpos($sourceUrl, '/') !== strlen($sourceUrl)) {
                $sourceUrl .= '/';
            }
            // Only preg_replace if there are replacements available
            if (preg_match('/\$[1-9]+/', $redirect->destinationUrl)) {
                $processedUrl = preg_replace($sourceUrl, $redirect->destinationUrl, $uri);
            } else {
                $processedUrl = $redirect->destinationUrl;
            }
        } else {
            return;
        }

        // Saving elements takes a while - we're going to do our incrementing
        // directly on the record instead.
        /** @var RedirectRecord $redirect */
        $redirectRecord = RedirectRecord::findOne($redirect->id);

        if ($redirectRecord) {
            $redirectRecord->hitCount++;
            $redirectRecord->hitAt = Db::prepareDateForDb(new \DateTime());
            $redirectRecord->save();
        }

        Craft::$app->response->redirect(UrlHelper::url($processedUrl), $redirect->statusCode)->send();

        try {
            Craft::$app->end();
        } catch (ExitException $e) {
            Craft::error($e->getMessage(), __METHOD__);
        }
    }

    /**
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function register404()
    {
        $catchAllService = Plugin::$plugin->catchAll;
        $settings = Plugin::getInstance()->getSettings();

        $fullPath = Craft::$app->request->getFullPath();
        $queryString = null;
        if (!$settings->stripQueryParameters) {
            $queryString = Craft::$app->request->getQueryString();
        }

        $catchAllService->registerHitByUri($fullPath, $queryString);
    }
}
