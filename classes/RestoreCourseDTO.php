<?php

namespace local_edusharing_webservice;

class RestoreCourseDTO
{
    public ?int $courseId;
    public ?int $restoreId;

    public function __construct($courseId, $restoreId) {
        $this->courseId = $courseId;
        $this->restoreId = $restoreId;
    }
}
