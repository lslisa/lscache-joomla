<?php

/**
 *  @since      1.2.0
 *  @author     LiteSpeed Technologies <info@litespeedtech.com>
 *  @copyright  Copyright (c) 2017-2018 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 *  @license    https://opensource.org/licenses/GPL-3.0
 */
defined('_JEXEC') or die;

/**
 * LiteSpeed Cache Plugin for Joomla running on LiteSpeed Webserver (LSWS).
 *
 * @since  1.0
 */
class plgSystemLSCache extends JPlugin
{

    const LOG_ERROR = 3;
    const LOG_INFO = 6;
    const LOG_DEBUG = 8;
    
    const MODULE_ESI = 1;
    const MODULE_PURGEALL = 2;
    const MODULE_PURGETAG = 3;
    const MODULE_EMBED = 4;
    const CATEGORY_CONTEXTS = array('com_categories.category', 'com_banners.category', 'com_contact.category', 'com_content.category', 'com_newsfeeds.category', 'com_users.category',
        'com_categories.categories', 'com_banners.categories', 'com_contact.categories', 'com_content.categories', 'com_newsfeeds.categories', 'com_users.categories');
    const CONTENT_CONTEXTS = array('com_content.article', 'com_content.featured', 'com_banner.banner', 'com_contact.contact', 'com_newsfeeds.newsfeed');

    protected $app;
    protected $cacheEnabled;
    protected $esiEnabled;
    protected $esion = false;
    protected $esittl = 0;
    protected $esipublic = true;
    protected $menuItem;
    protected $moduleHelper;
    protected $componentHelper;

    public $settings;
    public $lscInstance;
    public $pageElements = array();
    public $pageCachable = false;
    public $vary = array();
    public $cacheTags = array();
    public $purgeObject;

    /**
     * Read LSCache Settings.
     *
     * @since   0.1
     */
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->settings = JComponentHelper::getParams('com_lscache');
        if(empty($this->settings->get('cacheEnabled'))){
            $this->saveComponent(true);
            $this->saveHtaccess(true);
        }
        
        $this->cacheEnabled = $this->settings->get('cacheEnabled', 1) == 1 ? true : false;
        if (!$this->cacheEnabled) {
            return;
        }
        $this->esiEnabled = $this->settings->get('esiEnabled', 1) == 1 ? true : false;

        // Server type
        if (!defined('LITESPEED_SERVER_TYPE')) {
            if (isset($_SERVER['HTTP_X_LSCACHE']) && $_SERVER['HTTP_X_LSCACHE']) {
                define('LITESPEED_SERVER_TYPE', 'LITESPEED_SERVER_ADC');
            } elseif (isset($_SERVER['LSWS_EDITION']) && strpos($_SERVER['LSWS_EDITION'], 'Openlitespeed') === 0) {
                define('LITESPEED_SERVER_TYPE', 'LITESPEED_SERVER_OLS');
            } elseif (isset($_SERVER['SERVER_SOFTWARE']) && $_SERVER['SERVER_SOFTWARE'] == 'LiteSpeed') {
                define('LITESPEED_SERVER_TYPE', 'LITESPEED_SERVER_ENT');
            } else {
                define('LITESPEED_SERVER_TYPE', 'NONE');
            }
        }

        // Checks if caching is allowed via server variable
        if (!empty($_SERVER['X-LSCACHE']) || LITESPEED_SERVER_TYPE === 'LITESPEED_SERVER_ADC' || defined('LITESPEED_CLI')) {
            !defined('LITESPEED_ALLOWED') && define('LITESPEED_ALLOWED', true);
        } else {
            $this->cacheEnabled = false;
            return;
        }

        // ESI const defination
        if (!defined('LITESPEED_ESI_SUPPORT')) {
            define('LITESPEED_ESI_SUPPORT', LITESPEED_SERVER_TYPE !== 'LITESPEED_SERVER_OLS' ? true : false );
        }

        JLoader::register('LiteSpeedCacheBase', __DIR__ . '/lscachebase.php', true);
        JLoader::register('LiteSpeedCacheCore', __DIR__ . '/lscachecore.php', true);
        $this->lscInstance = new LiteSpeedCacheCore();

        JLoader::register('LSCacheModuleBase', __DIR__ . '/modules/base.php', true);
        JLoader::register('LSCacheModulesHelper', __DIR__ . '/modules/helper.php', true);
        $this->moduleHelper = new LSCacheModulesHelper($this);

        if (!$this->app) {
            $this->app = JFactory::getApplication();
        }

        JLoader::register('LSCacheComponentBase', __DIR__ . '/components/base.php', true);
        JLoader::register('LSCacheComponentsHelper', __DIR__ . '/components/helper.php', true);
        $this->componentHelper = new LSCacheComponentsHelper($this);

        $this->purgeObject = (object) array('tags' => array(), 'urls' => array(), 'option' => "", 'idField' => "", 'ids' => array(), 'purgeAll' => false, 'recacheAll' => false);
        $this->purgeObject->autoRecache = $this->settings->get('autoRecache', 0);
        
        $lang = JFactory::getLanguage();
        $lang->load('plg_system_lscache', JPATH_ADMINISTRATOR, null, false, true);        
    }

    /**
     * No cache for backend pages, for page with error messages, for PostBack request, for logged in user, for expired sessions, for exclude pages, etc.
     *
     * @since   1.0.0
     */
    public function onAfterRoute()
    {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->pageCachable = true;

        $app = $this->app;

        $this->menuItem = $app->getMenu()->getActive();
        if ($this->menuItem) {
            if ($this->menuItem->type == 'url') {
                $this->pageCachable = false;
                return;
            }
            $this->cacheTags[] = "com_menus:" . $this->menuItem->id;
            if ($this->menuItem->type == 'alias') {
                $menuParams = $this->menuItem->params;
                $menuid = $menuParams->get('aliasoptions');
                $this->cacheTags[] = "com_menus:" . $menuid;
            }
            $this->pageElements = $this->menuItem->query;
        } else {
            $link = JUri::getInstance()->getQuery();
            if (!empty($link)) {
                $this->pageElements = $this->explode2($link, '&', '=');
            } else if (!empty($app->input->get('option'))){
                $this->pageElements["option"] = $app->input->get('option');
            }
        }
        //$this->debug(__FUNCTION__ . var_export($this->pageElements,true));

        if (isset($this->pageElements["option"])) {
            $option = $this->pageElements["option"];
            $this->componentHelper->registerEvents($option);
        } else {
            $this->pageCachable = false;
            return;
        }

        if ($app->isAdmin()) {
            $this->pageCachable = false;
            if(!empty($option) && ($option=="com_templates") &&  isset($this->pageElements["view"]) && ($this->pageElements["view"]=="template")){
                $task = $app->input->get('task');
                if(!empty($task) && in_array($task, array("template.save", "template.apply", "template.delete"))){
                    $this->purgeTemplate(true);
                    $this->purgeAction();
                } else if(!empty($task)) {
                    $this->purgeTemplate(false);
                    $this->purgeAction();
                }
            }
        } else {
            $this->checkVary();
        }

        if (!$this->pageCachable) {
            
        } else if (JDEBUG) {
            $this->pageCachable = false;
        } else if (count($app->getMessageQueue())) {
            $this->pageCachable = false;
        } else if ($app->input->getMethod() != 'GET') {
            $this->pageCachable = false;
        } else if ($this->isExcluded()) {
            $this->pageCachable = false;
        }

        if (!$this->pageCachable) {
            $info = $_SERVER['HTTP_USER_AGENT'];
            if ($info == 'lscache_runner') {
                $app->close();
            }
        }
    }

    public function onAfterRenderModule($module, $attribs)
    {
        if (!$this->pageCachable) {
            return;
        }

        $tag = $this->moduleHelper->getModuleTags($module);
        $cacheType = $this->getModuleCacheType($module);

        $etag = 'com_modules:' . $module->id;
        if (!empty($module->lscache_tag)) {
            $etag .= ',' . $module->lscache_tag;
        }
        if (!empty($tag)) {
            $etag .= ',' . $tag;
        }
        
        if ($cacheType == self::MODULE_ESI) {
            if (!$this->esiEnabled) {
                $this->cacheTags[] = $etag ;
            } else if (LITESPEED_ESI_SUPPORT) {
                $tag = 'com_modules:' . $module->id;
                $device = "desktop";
                if ($this->app->client->mobile) {
                    $device = 'mobile';
                }

                if ($module->lscache_ttl == 0) {
                    $module->lscache_type = 0;
                }

                if ($module->lscache_type == 1) {
                    $module->content = '<esi:include src="index.php?option=com_lscache&view=esi&moduleid=' . $module->id . '&device=' . $device . '&language=' . JFactory::getLanguage()->getTag() . $this->getModuleAttribs($attribs) . '" cache-control="public,no-vary" cache-tag="' . $tag . '" />';
                } else if ($module->lscache_type == -1) {
                    $tag = 'public:' . $tag . ',' . $tag;
                    $module->content = '<esi:include src="index.php?option=com_lscache&view=esi&moduleid=' . $module->id . '&device=' . $device . '&language=' . JFactory::getLanguage()->getTag() . $this->getModuleAttribs($attribs) . '" cache-control="private,no-vary" cache-tag="' . $tag . '" />';
                } else if ($module->lscache_type == 0) {
                    $module->content = '<esi:include src="' . 'index.php?option=com_lscache&view=esi&moduleid=' . $module->id . $this->getModuleAttribs($attribs) . '" cache-control="no-cache"/>';
                }

                $this->esion = true;
                return;
            } else if (!LITESPEED_ESI_SUPPORT) {
                $this->cacheTags[] = $etag;
                if ($module->lscache_type == 0) {
                    $this->esittl = 0;
                } else {
                    $this->esittl = min($this->esittl, $module->lscache_ttl);
                    if ($module->lscache_type == -1) {
                        $this->esipublic = false;
                    }
                }

                $this->esion = true;
                return;
            }
        } else if ($cacheType == self::MODULE_EMBED) {
            $this->cacheTags[] = $etag ;
        } else if (!empty($tag)) {
            $this->cacheTags[] = $tag;
        }
    }

    public function onContentPrepare($context, &$row, &$params, $page = 0)
    {
        if (!$this->pageCachable) {
            return;
        }

        //$this->debug(__FUNCTION__ . $context . var_export($row,true) );

        if (strpos($context, "mod_") === 0) {
            return;
        }

        if (isset($this->pageElements["id"])) {
            if (empty($row->id)) {
                return;
            } else if ($row->id != $this->pageElements["id"]) {
                return;
            }
        } else if (isset($this->pageElements["context"])) {
            return;
        }

        $this->pageElements["context"] = $context;
        $this->pageElements["content"] = $row;
    }

    public function onAfterRender()
    {
        if (!$this->cacheEnabled) {
            return;
        }
        
        if ($this->purgeObject->recacheAll) {
            $this->purgeObject->recacheAll = false;
            $this->recacheAction();
            $this->app->redirect('index.php?option=com_lscache');
        }

        if (!$this->pageCachable) {
            return;
        }

        if (function_exists('http_response_code')) {
            $httpcode = http_response_code();
            if ($httpcode > 201) {
                $this->log("Http Response Code Not Cachable:" . $httpcode);
                return;
            }
        }

        if (isset($this->pageElements["context"])) {
            $context = $this->pageElements["context"];
            if ($context && in_array($context, self::CATEGORY_CONTEXTS)) {
                $context = 'com_categories.category';
            }
        }

        $option = $this->pageElements["option"];
        if (isset($context)) {
            $option = $this->getOption($context);
        }

        if (isset($this->pageElements["id"])) {
            $id = $this->pageElements["id"];
        }

        if (isset($this->pageElements["content"])) {
            $content = $this->pageElements["content"];
            if ($content && isset($content->id)) {
                $id = $content->id;
            }
        }

        if (empty($option) && !empty($this->menuItem)) {
            if (!$this->menuItem->home) {
                return;
            }
        } else if (isset($id) && in_array($option, array('com_content', 'com_contact', 'com_banners', 'com_newsfeed', 'com_categories', 'com_users'))) {
            $this->cacheTags[] = $option . ':' . $id;
        } else if ($this->componentHelper->supportComponent($option)) {
            $this->cacheTags[] = $this->componentHelper->getTags($option, $this->pageElements);
        } else if (isset($content) && $content instanceof JTable) {
            $tableName = str_replace('#__', "DB", $content->getTableName());
            $tag = $tableName . ':' . implode('-', $content->getPrimaryKey());
            $this->cacheTags[] = $tag;
        } else {
            $this->cacheTags[] = $option;
        }
        
        
        $templateName = $this->app->getTemplate();
        $view = isset($this->pageElements["view"])? $this->pageElements["view"] : "default";
        $layout = isset($this->pageElements["layout"]) ? $this->pageElements["layout"] : "default";
        $template = "template:" . implode("/", array($templateName, $option, $view,$layout));
        $this->cacheTags[] = $template ;

        $cacheTags = implode(',', $this->cacheTags);
        //$this->debug(__FUNCTION__ . $cacheTags . var_export($this->pageElements,true) );

        $cacheTimeout = $this->settings->get('cacheTimeout', 2000) * 60;
        if ($this->menuItem->home) {
            $cacheTimeout = $this->settings->get('homePageCacheTimeout', 2000) * 60;
        }

        if (!LITESPEED_ESI_SUPPORT && $this->esiEnabled && $this->esion) {
            $cacheTimeout = min($cacheTimeout, $this->esittl);
            if ($cacheTimeout == 0) {
                return;
            }
            $this->lscInstance->config(array("public_cache_timeout" => $cacheTimeout, "private_cache_timeout" => $cacheTimeout));
            if ($this->esipublic) {
                $this->lscInstance->cachePublic($cacheTags, $this->esion);
            } else {
                $this->lscInstance->cachePrivate($cacheTags, $this->esion);
            }
        } else {
            if ($cacheTimeout == 0) {
                return;
            }
            $this->lscInstance->config(array("public_cache_timeout" => $cacheTimeout, "private_cache_timeout" => $cacheTimeout));
            $this->lscInstance->cachePublic($cacheTags, $this->esion);
        }
        $this->log();
    }

    private function getOption($context)
    {
        $parts = explode(".", $context);
        return $parts[0];
    }

    public function onContentAfterSave($context, $row, $isNew)
    {
        if (!$this->cacheEnabled) {
            return;
        }
        $this->purgeContent($context, $row);
        $this->purgeAction();
    }

    public function onContentAfterDelete($context, $row)
    {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->purgeContent($context, $row);
        $this->purgeAction();
    }

    public function onUserAfterSave($user, $isNew, $success, $msg)
    {
        if (!$this->cacheEnabled) {
            return;
        }

        if (!$success) {
            return;
        }

        if ($isNew) {
            return;
        }

        $this->purgeContent("com_users.user", $user);
        $this->purgeAction();
    }

    public function onUserAfterLogin($options)
    {
        if (!$this->cacheEnabled) {
            return;
        }

        if ($this->app->isAdmin()) {
            return;
        }
        $this->lscInstance->checkPrivateCookie();
        $this->checkVary();
        if ($this->esiEnabled) {
            $this->lscInstance->purgeAllPrivate();
            $this->log();
        }
    }

    public function onUserAfterLogout($options)
    {
        if (!$this->cacheEnabled) {
            return;
        }

        if ($this->app->isAdmin()) {
            return;
        }
        $this->checkVary();
        if ($this->esiEnabled) {
            $this->lscInstance->purgeAllPrivate();
            $this->log();
        }
    }
    
    
    public function onUserLoginFailure($respond){
        $this->lscInstance->purgePrivate('joomla.login');
        $this->log();
    }
    

    public function onUserAfterDelete($user, $success, $msg)
    {
        if (!$this->cacheEnabled) {
            return;
        }

        if (!$success) {
            return;
        }
        $this->purgeContent("com_users.user", $user);
        $this->purgeAction();
        
    }

    public function purgeContent($context, $row)
    {
        if($this->purgeObject->purgeAll){
            return;
        }
        
        $option = $this->getOption($context);

        if (in_array($context, self::CATEGORY_CONTEXTS)) {
            $purgeTags = 'com_categories, com_categories:' . $row->id;
            $this->purgeObject->ids[] = $row->id;
            if ($row->parent_id) {
                $purgeTags .= ',com_categories:' . $row->parent_id;
                $this->purgeObject->ids[] = $row->parent_id;
            }
            $this->purgeObject->tags[] = $purgeTags;
            $this->purgeObject->option = empty($row->extension)? $option : $row->extension;
            $this->purgeObject->idField = 'id';
            return;
        }

        $menu_contexts = array('com_menus.item', 'com_menus.menu');
        if (in_array($context, $menu_contexts)) {
            $purgeTags = 'com_menus,com_menus:' . $row->id;
            if ($row->menutype) {
                $purgeTags .= ",com_menus:" . $row->menutype;
            }
            $menu = $row;
            while ($menu && ($menu->level > 1) && $menu->parent_id) {
                $purgeTags .= ',com_menus:' . $menu->parent_id;
                $menu = $this->app->getMenu()->getItem($menu->id);
            }
            $this->purgeObject->tags[] = $purgeTags;
            $this->purgeObject->urls[] = 'index.php?Itemid=' . $row->id;
            return;
        }

        if ($this->isOptionExcluded($option)) {
            return;
        }

        if (in_array($context, self::CONTENT_CONTEXTS)) {
            $purgeTags = $option . ',' . $option . ':' . $row->id;
            $this->purgeObject->ids[] = $row->id;
            if ($row->catid) {
                $purgeTags .= ',com_categories:' . $row->catid;
                $this->purgeObject->ids[] = $row->catid;
            }
            $this->purgeObject->tags[] = $purgeTags;
            $this->purgeObject->option = $option;
            $this->purgeObject->idField = 'id';
            return;
        }

        if ($context == "com_users.user") {
            $purgeTags = 'com_users,com_users:' . $row["id"];
            $this->purgeObject->tags[] = $purgeTags;
            $this->purgeObject->option = $option;
            $this->purgeObject->idField = 'id';
            $this->purgeObject[] = $row->id;
            return;
        }
        if($this->componentHelper->supportComponent($option)){
            $purgeTags = $this->componentHelper->onPurgeContent($option, $context, $row);
            return;
        }

        if ($row && $row instanceof JTable) {
            $tableName = str_replace('#__', "DB", $row->getTableName());
            $purgeTags = $tableName . ':' . implode('-', $row->getPrimaryKey());
            $this->purgeObject->tags[] = $purgeTags;
            $this->purgeObject->option = $option;
            return;
        }

        $this->purgeObject->option = $option;
        $this->purgeObject->tags[] = $option;
    }

    public function onContentChangeState($context, $pks, $value)
    {
        if (!$this->cacheEnabled) {
            return;
        }
        $option = $this->getOption($context);

        if ($option == "com_plugins") {
            foreach ($pks as $pk) {
                $row = JTable::getInstance('extension');
                $row->load($pk);
                $row->enabled = $value;
                $this->purgeExtension($context, $row);
            }
        } else if ($option == "com_modules") {
            foreach ($pks as $pk) {
                $row = JTable::getInstance('module');
                $row->load($pk);
                $row->published = $value;
                $this->purgeExtension($context, $row);
            }
        } else if ($this->isOptionExcluded($option)) {
            return;
        } else if ($option == "com_content") {
            foreach ($pks as $pk) {
                $row = JTable::getInstance('content');
                $row->load($pk);
                $row->state = $value;
                $this->purgeContent($context, $row);
            }
        } else if ($option == "com_categories") {
            foreach ($pks as $pk) {
                $row = JTable::getInstance('Category');
                $row->load($pk);
                $row->state = $value;
                $this->purgeContent($context, $row);
            }
        } else {
            foreach ($pks as $pk) {
                $row->id = $pk;
                $this->purgeContent($context, $row);
            }
        }
        $this->purgeAction();
    }

    public function onExtensionBeforeSave($context, $row, $isNew)
    {
        if (!$this->cacheEnabled) {
            return;
        }

        if ($context == "com_modules.module") {
            $menus = $this->getModuleMenuItems($row->id);
            $this->purgeObject->purgeMenu = $menus;
        }
    }

    public function onExtensionAfterSave($context, $row, $isNew)
    {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->purgeExtension($context, $row);
        $this->purgeAction();
        
    }

    public function onExtensionBeforeDelete($context, $row)
    {
        if (!$this->cacheEnabled) {
            return;
        }
        $this->purgeExtension($context, $row);
        
    }

    public function onExtensionAfterDelete($context, $row)
    {
        if (!$this->cacheEnabled) {
            return;
        }
        $this->purgeAction();
        
    }
    
    protected function purgeExtension($context, $row)
    {
        if($this->purgeObject->purgeAll){
            return;
        }
        
        
        $option = $this->getOption($context);
        if ($option == "com_plugins") {
            if ($this->settings->get("autoPurgePlugin", 0) == 1) {
                $this->purgeObject->purgeAll = true;
            } else if (!empty($row->element) && !empty($row->folder) && ($row->element == "lscache") && ($row->folder == "system")) {
                $this->purgeObject->purgeAll = true;
            }
            return;
        }

        if ($option == "com_languages") {
            if ($this->settings->get("autoPurgeLanguage", 0) == 1) {
                $this->purgeObject->purgeAll = true;
            }
            return;
        }

        if ($context == "com_templates.style") {
            if ($row->home) {
                $this->purgeObject->purgeAll = true;
                return;
            }

            $purgeTags = array();

            $db = JFactory::getDbo();

            $query = $db->getQuery(true)
                    ->select('id')
                    ->from('#__menu')
                    ->where($db->quoteName('template_style_id') . '=' . (int) $row->id);
            $db->setQuery($query);

            $menus = $db->loadObjectList();

            foreach ($menus as $menu) {
                $purgeTags[] = "com_menus:" . $menu->id;
                $this->purgeObject->urls[] = 'index.php?Itemid=' . $menu->id;
            }
            $this->purgeObject->tags[] = implode(',', $purgeTags);
            return;
        }

        if ($option == "com_modules") {
            $cacheType = $this->getModuleCacheType($row);

            if ($cacheType == self::MODULE_PURGEALL) {
                $this->purgeObject->purgeAll = true;
                return;
            }

            $purgeTags = "com_modules:" . $row->id;

            if ($cacheType == self::MODULE_PURGETAG) {
                $menu = $this->getModuleMenuItems($row->id);
                if (!empty($this->purgeObject->purgeMenu)) {
                    $menu = array_merge($menu, $this->purgeObject->purgeMenu);
                }
                $purgeMenu = array_unique($menu, SORT_NUMERIC);
                foreach ($purgeMenu as $menuid) {
                    $this->purgeObject->urls[] = 'index.php?Itemid=' . $menuid;
                    $purgeTags .= ',com_menus:' . $menuid;
                }
            }
            $this->purgeObject->tags[] = $purgeTags;
            return;
        }

        if ($context == "com_lscache.module") {
            $purgeTags = "com_modules:" . $row->moduleid;
            $this->purgeObject->tags[] = $purgeTags;
            return;
        }

        if ($option == "com_config") {
            if ($row->element == "com_lscache") {
                $settings = json_decode($row->params);
                $cacheEnabled = $settings->cacheEnabled;
                if (!$cacheEnabled) {
                    $this->purgeObject->purgeAll = true;
                }
            } else {
                $this->purgeObject->tags[] = $row->element;
                $this->purgeObject->option = $row->element;
            }
        }
    }

    public function getModuleMenuItems($moduleid)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true)
                ->select('menuid')
                ->from('#__modules_menu')
                ->where($db->quoteName('moduleid') . '=' . (int) $moduleid);
        $db->setQuery($query);
        $menus = $db->loadColumn();
        return $menus;
    }

    protected function explode2($str, $d1, $d2)
    {
        $result = array();

        $Parts = explode($d1, trim($str));
        foreach ($Parts as $part1) {
            list( $key, $val ) = explode($d2, trim($part1));
            $result[urldecode($key)] = urldecode($val);
        }

        return $result;
    }

    protected function implode2(array $arr, $d1, $d2)
    {
        $arr1 = array();

        foreach ($arr as $key => $val) {
            $arr1[] = urlencode($key) . $d2 . urlencode($val);
        }

        return implode($d1, $arr1);
    }

    public function onExtensionAfterInstall($installer, $eid)
    {
        if (!$this->cacheEnabled) {
            return;
        }
        $this->purgeInstallation($eid, true);
        $this->purgeAction();
        
    }

    public function onExtensionAfterUpdate($installer, $eid)
    {
        if (!$this->cacheEnabled) {
            return;
        }
        $this->purgeInstallation($eid);
        $this->purgeAction();
        
    }

    public function onExtensionBeforeUninstall($eid)
    {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->purgeInstallation($eid);
        $this->purgeAction();
    }
    
    public function onExtensionAfterUninstall($eid)
    {
        if (!$this->cacheEnabled) {
            return;
        }

        $this->purgeAction();
    }

    

    protected function purgeInstallation($eid, $isNew = false)
    {
        if($this->purgeObject->purgeAll){
            return;
        }

        $extension = $this->getExtension($eid);

        if (!$extension) {
            return;
        }

        if ($isNew && in_array($extension->type, array('template', 'module', 'file', 'component'))) {
            return;
        }

        if (in_array($extension->type, array('language', 'plugin'))) {
            if (($extension->element == "lscache") && ($extension->folder == "system")) {
                $this->purgeObject->purgeAll = true;
                return;
            }
            $this->purgeExtension("com_" . $extension->type . 's.extension', $extension);
            return;
        }

        if ($extension->type == "component") {
            if ($extension->element == "com_lscache") {
                $this->purgeObject->purgeAll = true;
                return;
            }
            if (!$this->isOptionExcluded($extension->element)) {
                $this->purgeObject->tags[] = $extension->element;
                $this->purgeObject->option = $extension->element;
            }
            return;
        }

        if ($extension->type == "template") {
            $template = $this->getTemplate($extension->element);
            {
                $this->purgeExtension("com_templates.style", $template);
            }
            return;
        }

        if ($extension->type == "module") {
            $modules = $this->getModules($extension->element);
            foreach ($modules as $module) {
                $this->purgeExtension("com_modules.module", $module);
            }
            return;
        }
    }

    
    protected function purgeTemplate($purge = false){
        
        if($this->purgeObject->purgeAll){
            return;
        }
        
        $extensionID = $this->pageElements["id"];
        $file = $this->pageElements["file"];
        if($purge && !empty($file) && !empty($extensionID)){
            $file = base64_decode($file);
            $elements = explode('/', $file);
            if(count($elements)<3){
                $purge=false;
            } else if($elements[1]!="html"){
                $purge=false;
            }
        }
        
        if($purge){
            if(substr($elements[2],0,4) == "mod_"){
                $modules = $this->getModules($elements[2]);
                foreach ($modules as $module) {
                    $this->purgeExtension("com_modules.module", $module);
                }
            } else if((substr($elements[2],0,4) == "com_") && (count($elements)==5)){
                $extension = $this->getExtension($extensionID);
                $layout = $elements[4];
                $layout = explode(".", $layout)[0];
                $layout = explode("_", $layout)[0];
                $this->purgeObject->tags[] = "template:" . implode("/", array($extension->element, $elements[2], $elements[3],$layout));
                $this->purgeObject->option = $elements[2];
                $this->purgeObject->idField = 'view';
                $this->purgeObject->ids[] = $elements[3];
                
            } else {
                $purge = false;
            }
        }
        
        if(!$purge){
            $this->app->enqueueMessage(JText::_('COM_LSCACHE_PLUGIN_TEMPLATEPURGEALL'), "message");
        }
        
    }

    
    private function getModule($moduleid)
    {

        $db = \JFactory::getDbo();
        $query = $db->getQuery(true)
                ->select('*')
                ->from('#__modules')
                ->where('id=' . $moduleid);
        $db->setQuery($query);
        $modules = $db->loadObjectList();
        if (count($modules) < 1) {
            return FALSE;
        } else {
            return $modules[0];
        }
    }

    public function getModuleCacheType($module)
    {

        $db = JFactory::getDbo();

        if (!empty($module->cache_type)) {
            return $module->cache_type;
        }

        $query = $db->getQuery(true)
                ->select('*')
                ->from('#__modules_lscache')
                ->where($db->quoteName('moduleid') . '=' . (int) $module->id);
        $db->setQuery($query);
        $rows = $db->loadObjectList();
        if (count($rows) > 0) {
            $module->cache_type = self::MODULE_ESI;
            $module->lscache_type = $rows[0]->lscache_type;
            $module->lscache_ttl = $rows[0]->lscache_ttl;
            return self::MODULE_ESI;
        }

        if(($this->settings->get('loginESI',1)==1) && ((stripos($module->module, 'login')!==FALSE) || (stripos($module->title, 'login')!==FALSE))) {
            $module->cache_type = self::MODULE_ESI;
            $module->lscache_type = -1;
            $module->lscache_ttl = 14;
            $module->lscache_tag = 'joomla.login';
            return self::MODULE_ESI;
        }
        
        $query1 = $db->getQuery(true)
                ->select('MIN(menuid)')
                ->from('#__modules_menu')
                ->where($db->quoteName('moduleid') . '=' . (int) $module->id);
        $db->setQuery($query1);
        $pages = (int) $db->loadResult();

        if ($pages === null) {
            $module->cache_type = self::MODULE_EMBED;
            return self::MODULE_EMBED;
        } else if (empty($module->position)) {
            $module->cache_type = self::MODULE_EMBED;
            return self::MODULE_EMBED;
        } else if ($pages <= 0) {
            $module->cache_type = self::MODULE_PURGEALL;
            return self::MODULE_PURGEALL;
        }

        $module->cache_type = self::MODULE_PURGETAG;
        return self::MODULE_PURGETAG;
    }

    protected function getModules($element)
    {

        $db = JFactory::getDbo();

        $query = $db->getQuery(true)
                ->select('*')
                ->from('#__modules')
                ->where($db->quoteName('module') . '="' . $element . '"')
                ->where($db->quoteName('published') . '=1');
                
        $db->setQuery($query);

        $modules = $db->loadObjectList();
        return $modules;
    }
    
    protected function getTemplate($element)
    {
        $db = JFactory::getDbo();

        $query = $db->getQuery(true)
                ->select('*')
                ->from('#__template_styles')
                ->where($db->quoteName('template') . '="' . $element . '"');
                
        $db->setQuery($query);

        $template = $db->loadObject();
        return $template;
    }
    

    protected function getExtension($eid)
    {
        $db = JFactory::getDbo();

        $query = $db->getQuery(true)
                ->select('*')
                ->from('#__extensions')
                ->where($db->quoteName('extension_id') . '=' . (int) $eid);
        $db->setQuery($query);

        $extension = $db->loadObjectList();

        if (count($extension) > 0) {
            return $extension[0];
        } else {
            return null;
        }
    }

    /**
     * a simple debug function only for development usage
     *
     * @since    0.1
     */
    public function debug($action)
    {
        $debugFile = "lscache.log";

        date_default_timezone_set("America/New_York");
        list( $usec, $sec ) = explode(' ', microtime());

        if (!defined("LOG_INIT")) {
            define("LOG_INIT", true);
            file_put_contents($debugFile, "\n\n" . date('m/d/y H:i:s') . substr($usec, 1, 4), FILE_APPEND);
        }
        file_put_contents($debugFile, date('m/d/y H:i:s') . substr($usec, 1, 4) . "\t" . $action . "\n", FILE_APPEND);
    }

    /**
     * log if logLevel below settings
     *
     * @since    1.1.0
     */
    public function log($content = null, $logLevel = self::LOG_INFO)
    {
        if ($content == null) {
            if (!$this->lscInstance) {
                return;
            }

            $content = $this->lscInstance->getLogBuffer();
        }

        //$this->debug($content);

        $logLevelSetting = $this->settings->get('logLevel', -1);
        if ($logLevelSetting < 0) {
            return;
        }

        if ($logLevel > $logLevelSetting) {
            return;
        }

        JLog::add($content, $logLevel, 'LiteSpeedCache Plugin');
    }

    protected function isOptionExcluded($option)
    {
        $excludeOptions = $this->settings->get('excludeOptions', array());
        $excludeOptions[] = "com_ajax";
        if ($excludeOptions && $option && in_array($option, (array) $excludeOptions)) {
            return true;
        }
        return false;
    }

    /**
     * Check if the page is excluded from the cache or not.
     *
     * @return   boolean  True if the page is excluded else false
     *
     * @since    0.1
     */
    protected function isExcluded()
    {
        $option = $this->pageElements["option"];
        if ($option && $this->isOptionExcluded($option)) {
            return true;
        }

        $excludeMenuItems = $this->settings->get('excludeMenus', array());
        if ($excludeMenuItems) {
            $menuItem = $this->menuItem;
            if ($menuItem && $menuItem->id && $excludeMenuItems && in_array($menuItem->id, (array) $excludeMenuItems, true)) {
                return true;
            }
        }

        // Check if regular expressions are being used
        $excludeURIs = $this->settings->get('excludeURLs', '');
        if (!$excludeURIs) {
            return false;
        }

        $exclusions = explode("\n", str_replace(array("\r\n", "\r"), "\n", $excludeURIs));
        if (!$exclusions) {
            return false;
        }

        $path = JUri::getInstance()->toString(array('path', 'query', 'fragment'));
        foreach ($exclusions as $exclusion) {
            if ($exclusion == '') {
                continue;
            }

            if ((strpos($exclusion, '/') !== FALSE) && (strpos($exclusion, '\/') === FALSE)) {
                $exclusion = str_replace('/', '\/', $exclusion);
            }

            if (preg_match('/' . $exclusion . '/is', $path)) {
                return true;
            }
        }
        return false;
    }

    protected function isLoginExcluded()
    {
        $excludeMenuItems = $this->settings->get('loginExcludeMenus', array());
        if ($excludeMenuItems) {
            $menuItem = $this->menuItem;
            if ($menuItem && $menuItem->id && $excludeMenuItems && in_array($menuItem->id, (array) $excludeMenuItems, true)) {
                return true;
            }
        }

        // Check if regular expressions are being used
        $excludeURIs = $this->settings->get('loginExcludeURLs', '');
        if (!$excludeURIs) {
            return false;
        }

        $exclusions = explode("\n", str_replace(array("\r\n", "\r"), "\n", $excludeURIs));
        if (!$exclusions) {
            return false;
        }

        $path = JUri::getInstance()->toString(array('path', 'query', 'fragment'));
        foreach ($exclusions as $exclusion) {
            if ($exclusion == '') {
                continue;
            }

            if ((strpos($exclusion, '/') !== FALSE) && (strpos($exclusion, '\/') === FALSE)) {
                $exclusion = str_replace('/', '\/', $exclusion);
            }

            if (preg_match('/' . $exclusion . '/is', $path)) {
                return true;
            }
        }
        return false;
    }

    //ESI Render;
    public function onAfterInitialise()
    {
        $app = $this->app;
        $option = $app->input->get('option');
        if ($option != "com_lscache") {
            return;
        }

        if ($app->isAdmin()) {
            return;
        }
        
        $cleancache = $app->input->get('cleancache');
        if (!empty($cleancache)) {
            $cleanWords = $this->settings->get('cleanCache', 'purgeAllCache');
            if ($cleancache != $cleanWords) {
                http_response_code(403);
                $app->close();
                return;
            }

            $tags = $app->input->get('tags');
            if(!empty($tags)){
                $purgeTags = base64_decode($tags);
                $this->lscInstance->purgePublic($purgeTags);
            } else{
                $this->lscInstance->purgeAllPublic();
                echo "<html><body><h2>All LiteSpeed Cache Purged!</h2></body></html>";
            }
            $this->log();
            $app->close();
            return;
        }

        if (!$this->esiEnabled) {
            http_response_code(403);
            $app->close();
            return;
        }

        if (LITESPEED_ESI_SUPPORT === FALSE) {
            http_response_code(403);
            $app->close();
            return;
        }

        $moduleid = $this->app->input->getInt('moduleid', -1);
        if ($moduleid == -1) {
            http_response_code(403);
            $app->close();
            return;
        }

        $module = $this->getModule($moduleid);
        if (!$module) {
            http_response_code(403);
            $app->close();
            return;
        }

        $tag1 = $this->moduleHelper->getModuleTags($module);
        $cacheType = $this->getModuleCacheType($module);
        if ($cacheType != self::MODULE_ESI) {
            http_response_code(403);
            $app->close();
            return;
        }

        $attribs = array();
        if (isset($_GET['attribs'])) {
            $attrib = $_GET['attribs'];
            $attribs = $this->explode2($attrib, ';', ',');
        }

        if (isset($_SERVER['HTTP_REFERER'])) {
            $uri = JURI::getinstance();
            $uri->setPath("");
            $uri->setQuery("");
            $uri->setFragment("");
            $uri->parse($_SERVER['HTTP_REFERER']);

            $appInstance = JApplication::getInstance('site');
            $router = $appInstance->getRouter();
            $result = $router->parse($uri);
            if(isset($result['itemid'])){
                $menuid=$result['itemid'];
                $app->getMenu()->setActive($menuid);
            }
        }


        $content = JModuleHelper::renderModule($module, $attribs);
        if ($content) {
            $tag = "com_modules:" . $module->id;
            if ($tag1 !== "") {
                $tag .= ',' . $tag1;
            }
            
            if(!empty($module->lscache_tag)){
                $tag .= ',' . $module->lscache_tag;
            }

            $this->moduleHelper->afterESIRender($module, $content);

            $cacheTimeout = $module->lscache_ttl * 60;
            $this->lscInstance->config(array("public_cache_timeout" => $cacheTimeout, "private_cache_timeout" => $cacheTimeout));
            if ($module->lscache_type == 1) {
                $this->lscInstance->cachePublic($tag);
                $this->log();
            } else if ($module->lscache_type == -1) {
                $this->lscInstance->cachePrivate($tag, $tag);
                $this->log();
            }

            $app->setBody($content);
            echo $app->toString();
            $app->close();
        }
    }

    private function getModuleAttribs(array $attribs)
    {
        if ($attribs && count($attribs) > 0) {
            $attrib = $this->implode2($attribs, ';', ',');
            $result = '&attribs=' . $attrib;
            return $result;
        }
        return '';
    }

    private function getVaryKey()
    {
        //$lang = JFactory::getLanguage();
        //. $lang->getDefault();
        // . $lang->getTag();

        if ($this->app->client->mobile && ($this->settings->get('mobileCacheVary', 0)==1)) {
            $this->vary['device'] = 'mobile';
        } else if (isset($this->vary['device'])) {
            unset($this->vary['device']);
        }

        $user = JFactory::getUser();
        if (!$user->get('guest')) {
            $this->lscInstance->checkPrivateCookie();
            $loginCachable = $this->settings->get('loginCachable', 0) == 1 ? true : false;
            if ($loginCachable) {
                if ($this->settings->get('loginCacheVary', 0)==1) {
                    $this->vary['login'] = 'true';
                } else if (!empty($this->settings->get('loginExcludeMenus'))) {
                    $this->vary['login'] = 'true';
                } else if (!empty($this->settings->get('loginExcludeURLs'))) {
                    $this->vary['login'] = 'true';
                } else if (isset($this->vary['login'])) {
                    unset($this->vary['login']);
                }

                if ($this->isLoginExcluded()) {
                    $this->pageCachable = false;
                }
            } else {
                $this->vary['login'] = 'true';
                $this->pageCachable = false;
            }
        } else if (isset($this->vary['login'])) {
            unset($this->vary['login']);
        }

        if (count($this->vary)) {
            ksort($this->vary);
            $varyKey = $this->implode2($this->vary, ',', ':');
            return $varyKey;
        } else {
            return '';
        }
    }

    /**
     *
     *  set or delete cache vary cookie, if cookie need no change return true;
     *
     * @since   0.1
     */
    private function checkVary($value = "")
    {

        if ($value == "") {
            $value = $this->getVaryKey();
        }

        $inputCookie = $this->app->input->cookie;

        if ($value == "") {
            if (isset($_COOKIE[LiteSpeedCacheBase::VARY_COOKIE])) {
                $inputCookie->set(LiteSpeedCacheBase::VARY_COOKIE, null, time() - 1);
                return false;
            }
            return true;
        }

        if (!isset($_COOKIE[LiteSpeedCacheBase::VARY_COOKIE])) {
            $inputCookie->set(LiteSpeedCacheBase::VARY_COOKIE, $value, 0);
            return false;
        }

        if ($_COOKIE[LiteSpeedCacheBase::VARY_COOKIE] != $value) {
            $inputCookie->set(LiteSpeedCacheBase::VARY_COOKIE, $value, 0);
            return false;
        }

        return true;
    }

    public function onLSCacheRebuildAll()
    {
        if (!$this->app->isAdmin()) {
            return;
        }

        if (!$this->cacheEnabled) {
            $this->app->enqueueMessage(JText::_('COM_LSCACHE_PLUGIN_TURNONFIRST'));
            return;
        }
        
        if (!function_exists('curl_version')) {
            $this->app->enqueueMessage(JText::_('COM_LSCACHE_PLUGIN_CURLNOTSUPPORT'));
            reutrn;
        }

        $this->purgeObject->recacheAll = true;
        return;
    }

    private function getSiteMap($option = "")
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true)
                ->select(array($db->quoteName('id'), $db->quoteName('link'), $db->quoteName('type'), $db->quoteName('path')))
                ->from('#__menu')
                ->where($db->quoteName('client_id') . '=0')
                ->where($db->quoteName('published') . '=1')
                ->where($db->quoteName('type') . 'in ("component","alias")');
        if (!empty($option)) {
            $query->where($db->quoteName('link') . ' like "%option=' . $option . '%"');
        }
        $query->order($db->quoteName('level') . ' ASC');

        $db->setQuery($query);
        $menus = $db->loadObjectList();

        if (!empty($menus) && is_array($menus)) {
            foreach ($menus as $menu) {
                if($menu->type!="alias"){
                    $menu->path = 'index.php?Itemid=' . $menu->id;
                }
            }
        }
        else{
            $menus = array();
        }
        return $menus;
    }

    private function crawlUrls($urls, $output = true)
    {
        set_time_limit(0);

        $count = count($urls);
        if ($count < 1) {
            return "";
        }

        $cached = 0;
        $acceptCode = array(200, 201);
        $begin = microtime();
        $success = 0;
        $current = 0;
        $appInstance = JApplication::getInstance('site');
        $router = $appInstance->getRouter();
        $root =  JUri::getInstance()->toString(array( 'scheme','host','port'));
        $recacheDuration = $this->settings->get('recacheDuration', 30) * 1000000;
        if ($output) {
            ob_implicit_flush(TRUE);
            echo '<h3>It may take several minutes</h3><br/>';
        }
        
        foreach ($urls as $url) {
            $start = microtime();
            $ch = curl_init();
            if($this->app->isAdmin()){
                $url =  $router->build($url);
                $url = str_replace("/administrator", "", $url);
            }
            else{
                $url = JRoute::_ ($url,FALSE);
            }
            
            curl_setopt($ch, CURLOPT_URL, $root . $url);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, 'lscache_runner');
            $buffer = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $this->log( $root . $url, self::LOG_DEBUG);

            if (in_array($httpcode, $acceptCode)) {
                $success++;
            }
            $current++;

            if ($output) {
                echo '*';
                if ($current % 10 == 0) {
                    echo floor($current * 100 / $count) . '%<br/>';
                }
            } else if (($current % 10 == 0) && ($this->microtimeMinus($begin, microtime())>$recacheDuration)) {
                break;
            }
            $end = microtime();
            $diff = $this->microtimeMinus($start, $end);
            usleep(round($diff));
            
        }

        $totalTime = round($this->microtimeMinus($begin, microtime()) / 1000000);
        if($count == $current){
            $msg = str_replace('%d', $totalTime, JText::_('COM_LSCACHE_PLUGIN_PAGERECACHED'));
        } else {
            $msg = str_replace('%d', $totalTime, JText::_('COM_LSCACHE_PLUGIN_PAGERECACHOVERTIME'));
        }
        return $msg;
    }

    private function microtimeMinus($start, $end)
    {
        list($s_usec, $s_sec) = explode(" ", $start);
        list($e_usec, $e_sec) = explode(" ", $end);
        $diff = ((int) $e_sec - (int) $s_sec) * 1000000 + ((float) $e_usec - (float) $s_usec) * 1000000;
        return $diff;
    }

    public function purgeAction()
    {
        if ((!$this->purgeObject->purgeAll) && (count($this->purgeObject->tags) < 1)) {
            return;
        }        
        
        if ($this->purgeObject->autoRecache > 0) {
            $root = JUri::root();
            $cleanWords = $this->settings->get('cleanCache', 'purgeAllCache');
            $url = $root . "index.php?option=com_lscache&cleancache=" . $cleanWords;
            if ($this->purgeObject->purgeAll) {
                $this->purgeObject->recacheAll = false;
            } else if (count($this->purgeObject->tags) > 0) {
                $purgeTags = implode(',', $this->purgeObject->tags);
                $tags = base64_encode($purgeTags);
                $url .= "&tags=" . $tags;
            }
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $buffer = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        if (!empty($httpcode) && in_array($httpcode, array(200, 201))) {
            usleep(100000);
            if ($this->purgeObject->autoRecache == 2) {
                $this->purgeObject->recacheAll = true;
            }
            $this->recacheAction($this->purgeObject->recacheAll);
        } else if ($this->purgeObject->purgeAll) {
            $this->lscInstance->purgeAllPublic();
            $this->log();
        } else if (count($this->purgeObject->tags) > 0) {
            $purgeTags = implode(',', $this->purgeObject->tags);
            $this->lscInstance->purgePublic($purgeTags);
            $this->log();
        }

        $purgeMessage = $this->settings->get('purgeMessage', 1) == 1 ? true : false;
        if ($this->app->isAdmin() && $purgeMessage) {
            $this->app->enqueueMessage(JText::_('COM_LSCACHE_PLUGIN_PURGEINFORMED'), "message");
        }
    }

    private function recacheAction($recacheAll = true)
    {
        if ($recacheAll) {
            $menus = $this->getSiteMap();
            $urls = array_map(function($menu){return $menu->path;}, $menus);
            $recacheComponent = $this->settings->get('recacheComponent', false);
            if ($recacheComponent) {
                $compUrls = $this->componentHelper->getComMap($recacheComponent);
                $urls = array_merge($urls, $compUrls);
            }
        } else if ($this->purgeObject->autoRecache > 0) {
            $urls = $this->purgeObject->urls;
            if (!empty($this->purgeObject->option)) {
                $menus = $this->getSiteMap($this->purgeObject->option);
                foreach ($menus as $menu) {
                    if (empty($this->purgeObject->idField)) {
                        //$urls[] = $menu->path;
                    } else if (count($this->purgeObject->ids) > 0) {
                        $query = $this->explode2($menu->link, '&', '=');
                        if (!isset($query[$this->purgeObject->idField])) {
                            $urls[] = $menu->path;
                        } else if (in_array($query[$this->purgeObject->idField], $this->purgeObject->ids)) {
                            $urls[] = $menu->path;
                        }
                    } else {
                        $query = $this->explode2($menu->link, '&', '=');
                        if (!isset($query[$this->purgeObject->idField])) {
                            $urls[] = $menu->path;
                        }
                    }
                }
            }
        }

        $showProgress = ($recacheAll && (!$this->purgeObject->recacheAll)) ? true : false;
        $msg = $this->crawlUrls($urls, $showProgress);
        $purgeMessage = $this->settings->get('purgeMessage', 1) == 1 ? true : false;

        if (!$this->app->isAdmin()) {
            return;
        } else if (!$purgeMessage){
            return;
        } else if ($this->purgeObject->purgeAll) {
            $msg = JText::_('COM_LSCACHE_PLUGIN_NEEDMANUALRECACHE');
            $this->app->enqueueMessage($msg, "message");
        } else if(!empty($msg)) {
            $this->app->enqueueMessage($msg, "message");
        } 
    }
    
    private function saveComponent($firstRun = false){
        if($firstRun){
            $this->settings->set('excludeOptions', array('com_users'));
        }
        
        if($this->settings->get('cleanCache', 'purgeAllCache')=="purgeAllCache"){
            $this->settings->set('cleanCache', md5((String)rand()));
        }
        $componentid = JComponentHelper::getComponent('com_lscache')->id;
        $table = JTable::getInstance('extension');
        $table->load($componentid);
        $table->bind(array('params' => $this->settings->toString()));
        $table->store();
    }
    
    
    private function saveHtaccess($firstRun = false){
        $htaccess = JPATH_ROOT . '/.htaccess';

        $directives = '### LITESPEED_CACHE_START - Do not remove this line' . PHP_EOL;
        $directives .= '<IfModule LiteSpeed>' . PHP_EOL;
        $directives .= 'CacheLookup on' . PHP_EOL;
        if($this->settings->get('mobileCacheVary',0)==1){
            $directives .= 'RewriteEngine On' . PHP_EOL;
            $directives .= 'RewriteCond %{HTTP_USER_AGENT} Mobile|Android|Silk/|Kindle|BlackBerry|Opera\ Mini|Opera\ Mobi [NC] RewriteRule .* - [E=Cache-Control:vary=ismobile]' . PHP_EOL;
        }
        $directives .= '</IfModule>' . PHP_EOL;
        $directives .= '### LITESPEED_CACHE_END';
        
        $pattern = '@### LITESPEED_CACHE_START - Do not remove this line.*?### LITESPEED_CACHE_END@s';
        
        if (file_exists($htaccess))
        {
            $content = file_get_contents($htaccess);
            $newContent = preg_replace($pattern, $directives, $content, -1, $count);
            
            if(($count<=0) && $firstRun){
                $newContent =  preg_replace('@\<IfModule\ LiteSpeed\>.*?\<\/IfModule\>@s', '', $content);
                $newContent =  preg_replace('@CacheLookup\ on@s', '', $newContent);
				file_put_contents($htaccess, $newContent . PHP_EOL . $directives . PHP_EOL);
            } else if($count>0) {
				file_put_contents($htaccess, $newContent);
            } else {
                file_put_contents($htaccess, PHP_EOL . $directives . PHP_EOL, FILE_APPEND);
            }
        } else {
				file_put_contents($htaccess, $directives);
        }
    }
    

}
