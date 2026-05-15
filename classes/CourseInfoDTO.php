<?php

namespace local_edusharing_webservice;

class CourseInfoDTO
{
    public string $type;
    /** @var string[] */
    public array $plugins;
    /** @var string[] */
    public array $thirdPartyPlugins;
    /** @var string[] */
    public array $missingPlugins;

    public function __construct($type, array $plugins = [], array $thirdPartyPlugins = [], array $missingPlugins = []) {
        $this->type = $type;
        $this->plugins = $plugins;
        $this->thirdPartyPlugins = $thirdPartyPlugins;
        $this->missingPlugins = $missingPlugins;
    }
}
