<?php

namespace local_edusharing_webservice;

class CourseInfoDTO
{
    public string $type;

    public function __construct($type) {
        $this->type = $type;
    }
}
