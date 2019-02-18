<?php

class PestCampaign{
    
    private $campaign_name;
    private $list_name;
    private $mailer_list_name;
    
    private $campaign;
    private $list;
    private $mailer_list;
    private $mailer_list_v3;
    
    private $base_temp;
    private $start_date;
	

    private $threshold;
	
	private $gddMethod;
	
	private $lowerThreshold;
	private $upperThreshold;
    
    public function __construct($campaign_name, $list_name, $mailer_list_name, $threshold, $base_temp=null, $gdd_method="simple", $start_date="January 1", $lower_threshold=null, $upper_threshold=null) {
        $this->campaign_name = $campaign_name;
        $this->list_name = $list_name;
        $this->mailer_list_name = $mailer_list_name;        
        
        $this->base_temp = $base_temp;
        $this->threshold = $threshold;
        $this->start_date = new DateTime($start_date);
		$this->gddMethod = $gdd_method;
		
		$this->lowerThreshold = $lower_threshold;
		$this->upperThreshold = $upper_threshold;
		
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
	
    function getMailerListV3() {
        return $this->mailer_list_v3;
    }	

    function setMailerListName($mailer_list_name) {
        $this->mailer_list_name = $mailer_list_name;
    }

    function setMailerList($mailer_list) {
        $this->mailer_list = $mailer_list;
    }
	
    function setMailerListV3($mailer_list_v3) {
        $this->mailer_list_v3 = $mailer_list_v3;
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
	
    function getLowerThreshold() {
        return $this->lowerThreshold;
    }
	
    function getUpperThreshold() {
        return $this->upperThreshold;
    }		
	
	function getGDDMethod(){
		return $this->gddMethod;
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
	
    function setLowerThreshold($threshold) {
        $this->lowerThreshold = $threshold;
    }	
	
    function setUpperThreshold($threshold) {
        $this->upperThreshold = $threshold;
    }	
	
    function setGDDMethod($method) {
        $this->gddMethod = $method;
    }	
	





}

