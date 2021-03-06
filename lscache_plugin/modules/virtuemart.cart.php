<?php

/* 
 *  @since      1.0.0
 *  @author     LiteSpeed Technologies <info@litespeedtech.com>
 *  @copyright  Copyright (c) 2017-2018 LiteSpeed Technologies, Inc. (https://www.litespeedtech.com)
 *  @license    https://opensource.org/licenses/GPL-3.0
 */

class LSCacheModuleVirtueMartCart extends LSCacheModuleBase
{
    public function getModuleTags(){
        if($this->plugin->getModuleCacheType($this->module)!=1){
            $this->module->cache_type = 1;
            $this->module->lscache_type = -1;
            $this->module->lscache_ttl = 120;
            $this->module->lscache_tag = 'com_virtuemart.cart';
        }
        return "";
    }
    
}