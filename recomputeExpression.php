<?php
/**
 * recomputeExpression Plugin for LimeSurvey
 * purpose is to offer abilty to recompute all expression in a survey
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2013-2020 Denis Chenu <http://sondages.pro>
 * @copyright 2013 Practice Lab <https://www.practicelab.com/>
 * @license GPL v3
 * @version 2.0.5
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */
class recomputeExpression extends PluginBase
{
    static protected $description = 'A plugin to recompute Expression for each response line';
    static protected $name = 'recomputeExpression';

    private $bIsAdmin=false;
    private $sStatus="error";
    private $sMessage="An error was occured";
    private $iNext=0;
    private $iUpdatedValueCount=0;
    private $iNulledValueCount=0;
    private $aUpdatedArray=array();

    private $_iSurveyId;
    private $_iResponseId;

    protected $settings = array(
        'bAllowNonAdmin' => array(
            'type' => 'checkbox',
            'label' => 'Allow non admin to update with srid',
            'default'=> false
        ),
        'sNullNoRelevance' => array(
            'type' => 'select',
            'label' => 'Validate all relevance for question, and null value if needed',
            'default'=> "deletenonvalues",
            'options' => array(
                "allways"=>"Allways (no test of deletenonvalues)",
                "deletenonvalues"=>"According to deletenonvalues config",
                "never"=>"Never"
            )
        ),
    );
    protected $storage = 'DbStorage';
    public function init() {
     
        // Add the script (everywhere)
        $this->subscribe('afterPluginLoad');
        //Can call plugin
        $this->subscribe('newDirectRequest');
        //$this->subscribe('beforeSurveySettings');
    }

    public function afterPluginLoad()
    {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        if (Yii::app() instanceof CConsoleApplication) {
            return;
        }
        // Hack to register only on browse response
        $event      = $this->getEvent();

        //$surveyid = Yii::app()->request->getparam('surveyId');
        $surveyid = Yii::app()->request->getparam('surveyId');

        //$responseid = Yii::app()->request->getparam('id');
        $responseid = Yii::app()->request->getparam('id');

        $aRecomputeVar=array(
            'jsonurl'=>Yii::app()->getController()->createUrl('plugins/direct', array('plugin' => 'recomputeExpression', 'function' => 'recompute')),
            'surveyId'=> $surveyid,
            'responseId' => $responseid,
            'isResponsePage' => true,
            'route' => Yii::app()->request->getPathInfo()
        );

        Yii::app()->getClientScript()->registerScript('aRecomputeVar','recomputeVar='.json_encode($aRecomputeVar),CClientScript::POS_BEGIN);
        Yii::app()->getClientScript()->registerScriptFile(Yii::app()->assetManager->publish(dirname(__FILE__) . '/js/recompute.js'));
    }
    public function newDirectRequest()
    {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        if($this->getEvent()->get('target') != get_class($this)) {
            return;
        }
        $oEvent = $this->event;
        $bAllowNonAdmin = $this->get('bAllowNonAdmin');
        if (!$bAllowNonAdmin && !$this->api->checkAccess('administrator'))
            throw new CHttpException(403, 'This action requires you to be logged in as super administrator.');
        if ($oEvent->get('target') == $this->getName() && $oEvent->get('function') == 'recompute')// Function is not needed
            $this->actionRecompute();
    }

    /**
    * Recompute a response line according to parameters in URL
    * 
    * @return json
    **/
    private function actionRecompute()
    {
        // Needed parameters sid: the survey id
        $this->_iSurveyId = $iSurveyId=(int)Yii::app()->request->getQuery('sid', 0);
        // Optionnal parameters : token
        $sToken=(string)Yii::app()->request->getQuery('token', "");
        // Optionnal parameters : srid
        $iResponseId=(int)Yii::app()->request->getQuery('srid', 0);
        // Optionnal parameters : srid
        $bDoNext=(bool)Yii::app()->request->getQuery('next', false);
        $sNullNoRelevance = $this->get('sNullNoRelevance');
        $bNullNoRelevance =($sNullNoRelevance=="allways" || ($sNullNoRelevance=="deletenonvalues" && Yii::app()->getConfig('deletenonvalues')));
        $aSurveyInfo=getSurveyInfo($iSurveyId);
        $bError=false;
        $sMessage="";

        if(!$aSurveyInfo || $aSurveyInfo['active']!="Y")
        {
            throw new CHttpException(400, 'Invalid Survey Id.');
        }
        if($sToken && !(tableExists('{{tokens_'.$aSurveyInfo['sid'].'}}') || $aSurveyInfo['anonymized']!="N"))
        {
            throw new CHttpException(400, 'Token table is not set or survey is anonymous.');
        }
        Yii::app()->setConfig('surveyID',$iSurveyId);
        $bIsTokenSurvey=tableExists("tokens_{$iSurveyId}");
        if(Permission::model()->hasSurveyPermission($iSurveyId,'responses','update'))
        {
            $this->bIsAdmin=true;// Admin view response and can update one by one. Else : only update if token or srid
        }
        $iNextSrid=0;

        // Find the oResponse according to parameters
        $oResponse=NULL;
        if($iResponseId)
        {
            $oResponse=SurveyDynamic::model($iSurveyId)->find("id = :id",array(':id'=>$iResponseId));
            if(!$oResponse)
                $this->sMessage="Invalid Response ID";
        }
        elseif($sToken)
        {
            $oResponse=SurveyDynamic::model($iSurveyId)->find("token = :token",array(':token'=>$sToken));
            if(!$oResponse)
                $this->sMessage="Invalid Token";
        }
        elseif($bDoNext && $this->bIsAdmin)
        {
            $oResponse=SurveyDynamic::model($iSurveyId)->find( 
                array(
                    'condition'=>'submitdate IS NOT NULL',
                    'order'=>'id'
                )
            );
            if(!$oResponse)
                $this->sMessage="No submited response";
        }

        if($oResponse)
        {
            $this->_iResponseId = $iResponseId=$oResponse->id;
            $aOldAnswers=$oResponse->attributes; // Not needed but keep it

            if(isset($oResponse->token) && $oResponse->token)
                $sToken=$oResponse->token;
            // Fill $_SESSION['survey_'.$iSurveyId]
            $_SESSION['survey_'.$iSurveyId]['srid']=$iResponseId;
            if(isset($oResponse->startlanguage ) && $oResponse->startlanguage )
            {
                $_SESSION['survey_'.$iSurveyId]['s_lang']=$oResponse->startlanguage;
                SetSurveyLanguage($iSurveyId, $oResponse->startlanguage);//$clang=>Yii->app->lang;
            }
            else
            {
                SetSurveyLanguage($iSurveyId);
            }

            $rooturl=Yii::app()->baseUrl . '/';
            $step=0;
            if(isset($oResponse->lastpage) && $oResponse->lastpage)
            {
                $_SESSION['survey_'.$iSurveyId]['prevstep']=-1;
                $_SESSION['survey_'.$iSurveyId]['maxstep'] = $oResponse->lastpage;
                $_SESSION['survey_'.$iSurveyId]['step'] = $oResponse->lastpage;
            }
            $_SESSION['survey_'.$iSurveyId]['LEMtokenResume']=true;
            $radix=getRadixPointData($aSurveyInfo['surveyls_numberformat']);
            $radix = $radix['separator'];
            $aEmSurveyOptions = array(
            'active' => ($aSurveyInfo['active'] == 'Y'),
            'allowsave' => ($aSurveyInfo['allowsave'] == 'Y'),
            'anonymized' => ($aSurveyInfo['anonymized'] != 'N'),
            'assessments' => ($aSurveyInfo['assessments'] == 'Y'),
            'datestamp' => ($aSurveyInfo['datestamp'] == 'Y'),
            'deletenonvalues'=>Yii::app()->getConfig('deletenonvalues'),
            'hyperlinkSyntaxHighlighting' => false,
            'ipaddr' => ($aSurveyInfo['ipaddr'] == 'Y'),
            'radix'=>$radix,
            'refurl' => (($aSurveyInfo['refurl'] == "Y" && isset($oResponse->refurl)) ? $oResponse->refurl : NULL),
            'savetimings' => ($aSurveyInfo['savetimings'] == "Y"),
            'surveyls_dateformat' => (isset($aSurveyInfo['surveyls_dateformat']) ? $aSurveyInfo['surveyls_dateformat'] : 1),
            'startlanguage'=>(isset($clang->langcode) ? $clang->langcode : $aSurveyInfo['language']),
            'target' => Yii::app()->getConfig('uploaddir').DIRECTORY_SEPARATOR.'surveys'.DIRECTORY_SEPARATOR.$aSurveyInfo['sid'].DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR,
            'tempdir' => Yii::app()->getConfig('tempdir').DIRECTORY_SEPARATOR,
            'timeadjust' => 0,
            'token' => (isset($sToken) ? $sToken : NULL),
            );
            LimeExpressionManager::SetDirtyFlag();
            buildsurveysession($iSurveyId,false);
            foreach($aOldAnswers as $column=>$value){
                if (in_array($column, $_SESSION['survey_'.$iSurveyId]['insertarray']) && isset($_SESSION['survey_'.$iSurveyId]['fieldmap'][$column]))
                {
                    $_SESSION['survey_'.$iSurveyId][$column]=$value;
                }
            }
            // Use ExpressionManager to fill Session .... 
            LimeExpressionManager::StartSurvey($iSurveyId,'survey',$aEmSurveyOptions);// This is needed for EM ; use survey to do whole Expression
            LimeExpressionManager::JumpTo(2,false,false,true) ;// To set all relevanceStatus 
            $aInsertArray=$_SESSION['survey_'.$iSurveyId]['insertarray'];
            $aFieldMap=$_SESSION['survey_'.$iSurveyId]['fieldmap'];
            $updatedValues=array('old'=>array(),'new'=>array());
            foreach($aOldAnswers as $column=>$value)
            {
                if (in_array($column,$aInsertArray) && isset($aFieldMap[$column]))
                {
                    Yii::import('application.helpers.viewHelper');
                    $sColumnName=viewHelper::getFieldCode($aFieldMap[$column]);
                    $bRelevance=true;
                    if($bNullNoRelevance && isset($aFieldMap[$column]['relevance']) && trim($aFieldMap[$column]['relevance']!=""))
                    {
                        $bRelevance= (bool)$this->_EMProcessString("{".$aFieldMap[$column]['relevance']."}");
                        if(!$bRelevance)
                        {
                            if(!is_null($oResponse->$column))
                            {
                                $updatedValues['old'][$sColumnName]=$oResponse->$column;
                                $updatedValues['new'][$sColumnName]=null;
                                $this->iNulledValueCount++;
                            }
                            $oResponse->$column= null;
                        }
                        
                    }
                    if ($aFieldMap[$column]['type'] == '*' && $bRelevance)
                    {
                        //($string, $questionNum=NULL, $replacementFields=array(), $debug=false, $numRecursionLevels=1, $whichPrettyPrintIteration=1, $noReplacements=false, $timeit=true, $staticReplacement=false)
                        $oldVal=$oResponse->$column;
                        $equation = $aFieldMap[$column]['question'];
                        $equationAttribute = QuestionAttribute::model()->find("qid = :qid and attribute = :attribute",array(":qid"=>$aFieldMap[$column]['qid'],":attribute"=>'equation'));
                        if(!empty($equationAttribute) && !empty($equationAttribute->value)) {
                            $equation = $equationAttribute->value;
                        }
                        $newVal=$oResponse->$column=$this->_EMProcessString($equation);
                        if($oldVal!=$newVal && ($oldVal && $newVal))
                        {
                            $updatedValues['old'][$sColumnName]=$oldVal;
                            $updatedValues['new'][$sColumnName]=$newVal;
                            $this->iUpdatedValueCount++;
                        }
                    }
                }
            }
            $oResponse->save();
            // Construct a message
            $this->sStatus="success";
            if($this->iUpdatedValueCount>1)
                $this->sMessage= sprintf('Responses %s updated: %s answers modified',$iResponseId,$this->iUpdatedValueCount);
            elseif($this->iUpdatedValueCount>0)
                $this->sMessage= sprintf('Responses %s updated: one answer modified',$iResponseId);
            else
                $this->sMessage= sprintf('Responses %s updated: no answer modified',$iResponseId);

            if($this->iNulledValueCount>0)
                $this->sMessage.=sprintf(' (%s was nulled)',$this->iNulledValueCount);

            if(!$this->bIsAdmin)
                $this->sMessage=  sprintf('Responses %s updated.',$iResponseId);

            // If needed find the next (only with according rigths
            if($bDoNext && $this->bIsAdmin)
            {
                $oNextResponse=SurveyDynamic::model($iSurveyId)->find(array(
                                                                    'select'=>'id',
                                                                    'condition'=>'id>:id AND submitdate IS NOT NULL',
                                                                    'params'=>array('id'=>$iResponseId),
                                                                    'order'=>'id',
                                                                ));
                if($oNextResponse)
                    $this->iNext=$oNextResponse->id;
            }
        }
        $this->displayJson();
    }

    private function displayJson()
    {
        Yii::import('application.helpers.viewHelper');
        viewHelper::disableHtmlLogging();
        header('Content-type: application/json');
        echo json_encode(array(
            "status"=>$this->sStatus,
            "message"=>$this->sMessage,
            "next"=>$this->iNext
            ));
        die();
    }

    /**
     * Process a string via expression manager (static way)
     * @param string $string
     * @return string
     */
    private function _EMProcessString($string)
    {
        Yii::app()->setConfig('surveyID',$this->_iSurveyId);
        $oSurvey=Survey::model()->findByPk($this->_iSurveyId);
        $replacementFields=array(
            'SAVEDID'=>$this->_iResponseId,
            'SITENAME'=>App()->getConfig('sitename'),
            'SURVEYNAME'=>$oSurvey->getLocalizedTitle(),
            'SURVEYRESOURCESURL'=> Yii::app()->getConfig("uploadurl").'/surveys/'.$this->_iSurveyId.'/'
        );
        if(intval(Yii::app()->getConfig('versionnumber'))<3) {
            return \LimeExpressionManager::ProcessString($string, null, $replacementFields, false, 3, 0, false, false, true);
        }
        if(version_compare(Yii::app()->getConfig('versionnumber'),"3.6.2","<")) {
            return \LimeExpressionManager::ProcessString($string, null, $replacementFields, 3, 0, false, false, true);
        }
        return \LimeExpressionManager::ProcessStepString($string, true, 3, $replacementFields);
    }
}
