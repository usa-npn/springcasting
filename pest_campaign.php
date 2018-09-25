<?php

class PestCampaign{
    
    private $campaign_name;
    private $list_name;
    private $mailer_list_name;
    
    private $campaign;
    private $list;
    private $mailer_list;
    
    private $base_temp;
    private $start_date;
    private $threshold;
    
    public function __construct($campaign_name, $list_name, $mailer_list_name, $base_temp, $threshold, $start_date="January 1") {
        $this->campaign_name = $campaign_name;
        $this->list_name = $list_name;
        $this->mailer_list_name = $mailer_list_name;        
        
        $this->base_temp = $base_temp;
        $this->threshold = $threshold;
        $this->start_date = new DateTime($start_date);
    }
    
    function getCampaignName() {
        return $this->campaign_name;
    }

    function getListName() {
        return $this->list_name;
    }

    function setCampaignName($campaign_name) {
        $this->campaign_name = $campaign_name;
    }

    function setListName($list_name) {
        $this->list_name = $list_name;
    }
    
    function getCampaign() {
        return $this->campaign;
    }

    function getList() {
        return $this->list;
    }

    function setCampaign($campaign) {
        $this->campaign = $campaign;
    }

    function setList($list) {
        $this->list = $list;
    }

    function getMailerListName() {
        return $this->mailer_list_name;
    }

    function getMailerList() {
        return $this->mailer_list;
    }

    function setMailerListName($mailer_list_name) {
        $this->mailer_list_name = $mailer_list_name;
    }

    function setMailerList($mailer_list) {
        $this->mailer_list = $mailer_list;
    }

    function getBaseTemp() {
        return $this->base_temp;
    }

    function getStartDate() {
        return $this->start_date;
    }

    function getThreshold() {
        return $this->threshold;
    }

    function setBaseTemp($base_temp) {
        $this->base_temp = $base_temp;
    }

    function setStartDate($start_date) {
        $this->start_date = $start_date;
    }

    function setThreshold($threshold) {
        $this->threshold = $threshold;
    }




}

