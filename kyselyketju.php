<?php

use \LimeSurvey\Menu\MenuItem;

$ls_xlsxwriter_path = realpath(dirname(__FILE__) . '/../../application/third_party/xlsx_writer/xlsxwriter.class.php');
if (file_exists($ls_xlsxwriter_path)) {
    require_once $ls_xlsxwriter_path;
} else {
    include_once(__DIR__ . '/php_xlsxwriter/xlsxwriter.class.php');
}

class Kyselyketju extends PluginBase
{
    protected $storage = 'DbStorage';
    static protected $description = 'Kyselyketjun luonti';
    static protected $name = 'Kyselyketju';

    public $allowedPublicMethods = [
        'SCExport',
    ];

    public function init()
    {
        Yii::setPathOfAlias('kyselyketju', dirname(__FILE__));
        $this->subscribe('beforeToolsMenuRender');
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
    }

    public function beforeToolsMenuRender()
    {
        $oEvent = $this->event;
        $surveyId = $oEvent->get('surveyId');

        if ($this->get('bUse', 'Survey', $surveyId) == 1) {
            $href = Yii::app()->createUrl(
                'admin/pluginhelper',
                array(
                    'sa' => 'sidebody',
                    'plugin' => 'Kyselyketju',
                    'method' => 'SCExport',
                    'surveyId' => $surveyId,
                )
            );

            $menuItem = new MenuItem(
                array(
                    'label' => gT("Export surveychain"),
                    'iconClass' => 'fa fa-table',
                    'href' => $href
                )
            );

            $oEvent->append('menuItems', array($menuItem));
        }
    }

    public function SCExport($surveyId)
    {
        //Survey chain export
        $oSurvey = Survey::model()->findByPk($surveyId);
        if (!$oSurvey) {
            throw new CHttpException(404, gT("This survey does not seem to exist."));
        }
        $baseLang = $oSurvey->language;

        $testResponses = SurveyDynamic::model($surveyId)->findAll();
        if (!$testResponses) {
            throw new CHttpException(404, gT("Responses not found for the given survey."));
        }

        $oaQuestions = Question::model()->findAllByAttributes(array('sid' => $surveyId, 'type' => 'M'));
        if (!$oaQuestions) {
            throw new CHttpException(404, gT("Questions type 'M' not found for the given survey."));
        }

        $aQuestions = array();
        foreach ($oaQuestions as $question) {
            if ($question->type == 'M') {
                $aQuestions[] = array('title' => $question->title, 'qid' => $question->qid);
            }
        }

        $chosen_question = $this->get('choiceQuestion', 'Survey', $surveyId, null);
        $chosen_question_id = $aQuestions[$chosen_question]['qid'];

        if (intval(App()->getConfig('versionnumber')) < 4) {
            $oaSubquestions = Question::model()->findAllByAttributes(array('sid' => $surveyId, 'parent_qid' => $chosen_question_id, 'language' => $baseLang));
        } else {
            $oaSubquestions = Question::model()->with(array('questionl10ns' => array('condition' => 'language = :language', 'params' => array(':language' => $baseLang))))->findAllByAttributes(array('parent_qid' => $chosen_question_id), array('index' => 'qid'));
        }

        $aAnswerOptions = array();

        if (intval(App()->getConfig('versionnumber')) < 4) {
            foreach ($oaSubquestions as $subquestion) {
                if ($subquestion->attributes['language'] == $baseLang) {
                    $aAnswerOptions[$subquestion->attributes['title']] = $subquestion->attributes['question'];
                }
            }
        } else {
            foreach ($oaSubquestions as $subquestion) {
                $aAnswerOptions[$subquestion->title] = $subquestion->questionl10ns["{$baseLang}"]->question;
            }
        }

        $settingslinks = [];

        if (version_compare(phpversion(), '8.0.0') < 0) {
            foreach ($aAnswerOptions as $key => $label) {
                $link = $this->get("{$label}", "Survey", $surveyId, null);
                $settingslinks[$label] = strpos($link, '?') !== false ? substr($link, 0, -8) : $link;
            }
        } else {
            foreach ($aAnswerOptions as $key => $label) {
                $link = $this->get("{$label}", "Survey", $surveyId, null);
                $settingslinks[$label] = str_contains($link, '?') ? substr($link, 0, -8) : $link;
            }
        }

        ini_set('display_errors', 0);
        ini_set('log_errors', 1);
        error_reporting(E_ALL & ~E_NOTICE);

        $filename = "response_data_process.xlsx";

        header('Content-disposition: attachment; filename="' . XLSXWriter::sanitize_filename($filename) . '"');
        header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        $writer = new XLSXWriter();
        $writer->setAuthor('LS');

        //1st sheet (application responses + linked other survey responses)

        $testResponses = SurveyDynamic::model($surveyId)->findAll();
        if (!$testResponses) {
            throw new CHttpException(404, gT("Test responses does not exist"));
        }

        //Getting survey id's from their links
        $finalArrayTest = array();
        foreach ($settingslinks as $key => $value) {
            $finalArrayTest[$key] = preg_replace('/.*\/(\d{6})$/', '$1', $value);
        }

        foreach ($testResponses as $response) {
            $sResponseId = $response->attributes['id'];
            $responseToken = $response->attributes['token'];
            $responseLang = $response->attributes['startlanguage'];

            //Getting questions and responses as text instead of codes
            $prettyApplicationResponse = getFullResponseTable($surveyId, $sResponseId, $responseLang); // jokainen response

            //Deleting HTML tags
            foreach ($prettyApplicationResponse as &$subArray) {
                $subArray = array_map('strip_tags', $subArray);
            }

            $newApplicationResponse = array();
            foreach ($prettyApplicationResponse as $key => $value) {
                if ($value[1]) {
                    $newApplicationResponse[$value[1] . ' ' . $value[0]] = $value[2];
                } else {
                    $newApplicationResponse[$value[0]] = $value[2];
                }
            }

            $header = array_keys($newApplicationResponse);
            $writer->writeSheetRow(
                'Sheet1',
                $header,
                array('font-style' => 'bold', 'fill' => '#70cc68')
            );
            $writer->writeSheetRow('Sheet1', array_values($newApplicationResponse));

            $arrayWithNoDeletionTwo = array();
            foreach ($finalArrayTest as $key => $surveyIds) {
                $survey = Survey::model()->findByPk($surveyIds);
                if (!$survey) {
                    unset($finalArrayTest[$key]); // Remove the deleted survey from the array
                } else {
                    $arrayWithNoDeletionTwo[] = $surveyIds;
                }
            }

            foreach ($arrayWithNoDeletionTwo as $surveyIds) {

                $matchingResponse = SurveyDynamic::model($surveyIds)->findByAttributes(array('token' => $responseToken));
                if ($matchingResponse) {
                    //Getting questions and responses as text instead of codes
                    $eachResponseId = $matchingResponse->attributes['id'];
                    $eachResponseLang = $matchingResponse->attributes['startlanguage'];
                    $prettyResponseEach = getFullResponseTable($surveyIds, $eachResponseId, $eachResponseLang);
                    //Deleting HTML tags
                    foreach ($prettyResponseEach as &$subArray) {
                        $subArray = array_map('strip_tags', $subArray);
                    }
                    $newPrettyResponseEach = array();
                    foreach ($prettyResponseEach as $key => $value) {
                        if ($value[1]) {
                            $newPrettyResponseEach[$value[1] . ' ' . $value[0]] = $value[2];
                        } else {
                            $newPrettyResponseEach[$value[0]] = $value[2]; //Ready edited every response
                        }
                    }
                    $header = array_keys($newPrettyResponseEach);
                    $survey_title = SurveyLanguageSetting::model()->findByAttributes(array('surveyls_survey_id' => $surveyIds, 'surveyls_language' => $eachResponseLang))->surveyls_title;
                    if (!$survey_title) {
                        $survey_title = SurveyLanguageSetting::model()->findByAttributes(array('surveyls_survey_id' => $surveyIds, 'surveyls_language' => $responseLang))->surveyls_title;
                    }
                    $writer->writeSheetRow(
                        'Sheet1',
                        [$survey_title],
                        array('font-style' => 'bold', 'fill' => '#70cc68')
                    );
                    $writer->writeSheetRow(
                        'Sheet1',
                        $header,
                        array('font-style' => 'bold', 'fill' => '#E1E1E1')
                    );
                    $writer->writeSheetRow('Sheet1', array_values($newPrettyResponseEach));
                }
            }
        }
        ///1st sheet end

        //2nd sheet - Application survey responses

        $sResponseIdHeader = $testResponses[0]->attributes['id'];
        $responseLangHeader = $testResponses[0]->attributes['startlanguage'];

        $prettyApplicationResponseHeader = getFullResponseTable($surveyId, $sResponseIdHeader, $responseLangHeader);

        //Deleting HTML tags
        foreach ($prettyApplicationResponseHeader as &$subArray) {
            $subArray = array_map('strip_tags', $subArray);
        }

        $newApplicationResponseHeader = array();
        foreach ($prettyApplicationResponseHeader as $key => $value) {
            if ($value[1]) {
                $newApplicationResponseHeader[$value[1] . ' ' . $value[0]] = $value[2];
            } else {
                $newApplicationResponseHeader[$value[0]] = $value[2];
            }
        }

        $header = array_keys($newApplicationResponseHeader);
        $writer->writeSheetRow(
            'Hakemus',
            $header
        );


        //Applicaiton's responses
        foreach ($testResponses as $response) {
            $sResponseId = $response->attributes['id'];
            $responseToken = $response->attributes['token'];
            $responseLang = $response->attributes['startlanguage'];

            //Getting questions and responses as text instead of codes
            $prettyApplicationResponse = getFullResponseTable($surveyId, $sResponseId, $responseLang); //Each response

            //Deleting HTML tags
            foreach ($prettyApplicationResponse as &$subArray) {
                $subArray = array_map('strip_tags', $subArray);
            }

            $newApplicationResponse = array();
            foreach ($prettyApplicationResponse as $key => $value) {
                if ($value[1]) {
                    $newApplicationResponse[$value[1] . ' ' . $value[0]] = $value[2];
                } else {
                    $newApplicationResponse[$value[0]] = $value[2];
                }
            }

            $writer->writeSheetRow('Hakemus', array_values($newApplicationResponse));
        }
        //2nd sheet end

        //Other surveys' responses from the survey chain
        $arrayWithNoDeletion = array();
        foreach ($finalArrayTest as $surveyIds) {
            $survey = Survey::model()->findByPk($surveyIds);
            if (!$survey) {
                continue; // Skip this iteration and move on to the next one
            }
            $arrayWithNoDeletion[] = $surveyIds;
        }

        foreach ($arrayWithNoDeletion as $surveyIds) {
            $eachSurveyResponses = SurveyDynamic::model($surveyIds)->findAll();

            $survey_title = SurveyLanguageSetting::model()->findByAttributes(array('surveyls_survey_id' => $surveyIds, 'surveyls_language' => $baseLang))->surveyls_title;
            $sResponseIdHeader = $eachSurveyResponses[0]->attributes['id'];
            $responseLangHeader = $eachSurveyResponses[0]->attributes['startlanguage'];

            $prettyApplicationResponseHeader = getFullResponseTable($surveyIds, $sResponseIdHeader, $responseLangHeader);

            //Deleting HTML tags
            foreach ($prettyApplicationResponseHeader as &$subArray) {
                $subArray = array_map('strip_tags', $subArray);
            }

            $newApplicationResponseHeader = array();
            foreach ($prettyApplicationResponseHeader as $key => $value) {
                if ($value[1]) {
                    $newApplicationResponseHeader[$value[1] . ' ' . $value[0]] = $value[2];
                } else {
                    $newApplicationResponseHeader[$value[0]] = $value[2];
                }
            }

            $header = array_keys($newApplicationResponseHeader);
            $writer->writeSheetRow(
                $survey_title,
                $header
            );
            foreach ($eachSurveyResponses as $response) {
                $sResponseId = $response->attributes['id'];
                $responseToken = $response->attributes['token'];
                $responseLang = $response->attributes['startlanguage'];

                //Getting questions and responses as text instead of codes
                $prettyApplicationResponse = getFullResponseTable($surveyIds, $sResponseId, $responseLang);

                //Deleting HTML tags
                foreach ($prettyApplicationResponse as &$subArray) {
                    $subArray = array_map('strip_tags', $subArray);
                }

                $newApplicationResponse = array();
                foreach ($prettyApplicationResponse as $key => $value) {
                    if ($value[1]) {
                        $newApplicationResponse[$value[1] . ' ' . $value[0]] = $value[2];
                    } else {
                        $newApplicationResponse[$value[0]] = $value[2];
                    }
                }

                $writer->writeSheetRow($survey_title, array_values($newApplicationResponse));
            }
        }

        $writer->writeToStdOut();
        exit(0);
    }

    public function findParentApplication() //Finding parent application of each survey
    {
        $applicationSurveys = PluginSetting::model()->findAll(array('condition' => "`key`='bUse' AND `value`='\"1\"'"));

        $applicationSurveysData = array();
        foreach ($applicationSurveys as $survey) {
            $surveyId = $survey->model_id;
            $settings = array();
            $pluginSettings = PluginSetting::model()->findAllByAttributes(array('model_id' => $surveyId));
            foreach ($pluginSettings as $pluginSetting) {
                $key = $pluginSetting->key;
                $value = $pluginSetting->value;
                $matches = array();
                if (preg_match('/\d{6}/', $value, $matches)) {
                    $settings[$key] = $matches[0];
                } else {
                    unset($settings[$key]);
                }
            }
            $applicationSurveysData[$surveyId] = $settings;
        }

        $currentUrl = Yii::app()->request->getUrl();
        preg_match('/\d{6}/', $currentUrl, $matches);
        $searchSequence = $matches[0];

        $found = false;
        $result = '';
        foreach ($applicationSurveysData as $surveyId => $settings) {
            foreach ($settings as $key => $value) {
                if (preg_match('/\d{6}/', $value, $matches) && $matches[0] === $searchSequence) {
                    $result = $surveyId;
                    $found = true;
                    break 2;
                } else if (preg_match('/\d{6}/', $surveyId, $matches) && $matches[0] === $searchSequence) {
                    $result = $surveyId . ' (Parent Application Survey)';
                    $found = true;
                    break 2;
                }
            }
        }

        if (!$found) {
            $result = null;
        }

        return $result;
    }

    public function beforeSurveySettings()
    {
        $oEvent     = $this->event;
        $sSurveyId = $oEvent->get('survey');

        $oaSurvey = Survey::model()->findByPk($sSurveyId);
        $baseLang = $oaSurvey->attributes['language'];

        $oaQuestions = Question::model()->findAllByAttributes(array('sid' => $sSurveyId, 'type' => 'M', 'language' => $baseLang));

        $aQuestions = array();
        foreach ($oaQuestions as $question) {
            if ($question->type == 'M') {
                $aQuestions[] = array('title' => $question->title, 'qid' => $question->qid);
            }
        }

        if (!$aQuestions) {
            $sWarningQuestions = '<br/><span style="color: red;">Monivalintakysymyksiä ei löytynyt!</span>';
        }


        $aApplicationSurveys = PluginSetting::model()->findAll(array('condition' => "`key`='bUse' AND `value`='\"1\"'"));

        $aApplicationSurveysId = array();
        foreach ($aApplicationSurveys as $survey) {
            $aApplicationSurveysId[] = $survey->model_id;
        }

        $sWarning = '';
        $sWarningQuestions = '';

        //Message about how many application surveys there are
        if (count($aApplicationSurveysId) > 1) {
            $sWarning = '<br/><span style="color: blue;">Hakemuskyselyjä yhteensä: ' . count($aApplicationSurveysId) . '</span><br/>';

            $surveyNames = "";

            foreach ($aApplicationSurveysId as $surveyId) {
                $survey = Survey::model()->findByPk($surveyId);
                $ownBaseLang = $survey->attributes['language'];
                $survey_title = SurveyLanguageSetting::model()->findByAttributes(array('surveyls_survey_id' => $surveyId, 'surveyls_language' => $ownBaseLang))->surveyls_title;
                $surveyNames .= $survey_title . ", ";
            }

            // Remove trailing comma and space from surveyNames
            $surveyNames = rtrim($surveyNames, ", ");

            $sWarning .= "<span style='color: blue;'>" . $surveyNames . "</span>";
        }

        $aSettings = array(
            'bUse' => array(
                'type' => 'select',
                'label' => 'Onko tämä Hakemuskysely?',
                'options' => array(
                    0 => gT("No"),
                    1 => gT("Yes")
                ),
                'default' => 0,
                'help' => 'Jos tämä on Hakemuskysely, valitse "Kyllä"' . $sWarning,
                'current' => $this->get('bUse', 'Survey', $oEvent->get('survey')),
            ),
        );

        if ($this->get('bUse', 'Survey', $oEvent->get('survey')) == 1) {
            $aSettings['infoUse'] = array(
                'type' => 'info',
                'content' => '<h3><b>Miten saa kyselyketjun toimimaan parhaiten?</b></h3> <br/><ul><li>Varmista, että alhaalla liitetyissä linkeissä ei ole liiallisia välilyöntejä alussa. Linkissä voi olla tai voi puuttuakin se "?lang=xx" osa, tärkeintä on että ei ole mitään muita parameja</li><li>Jos kyselyketjun aikana tulevassa kyselyssä ei ole samaa aloituskieltä, kysely käynnistyy peruskielellään</li></ul>',
            );
            $aSettings['choiceQuestion'] = array(
                'type' => 'select',
                'htmlOptions' => array(
                    'empty' => gT("None"),
                    'options' => $aQuestions['options'],
                ),
                'default' => 'empty',
                'label' => "Kysymys, josta otetaan testit",
                'options' => array_column($aQuestions, 'title'),
                'current' => $this->get('choiceQuestion', 'Survey', $sSurveyId, null),
                'help' => "Vain 'Monivalinta' kysymystyypit" . $sWarningQuestions,
            );
            // $aSettings['tokensOption'] = array(
            //     'type' => 'select',
            //     'label' => 'Tokenit:',
            //     'options' => array(
            //         0 => 'Yksi token jokaiseen kyselyyn',
            //         1 => 'Erilaiset generoidut tokenit'
            //     ),
            //     'default' => 0,
            //     'help' => 'Jos tähän kyselyyn on luotu osallistujien lista tunnuksineen ja haluat että kaikissa kyselyissä on yksi sama tunnus, valitse ensimmäinen vaihtoehto. Jos haluat automaattisesti generoidut tunnukset kyselyketjussa, valitse toinen vaihtoehto',
            //     'current' => $this->get('tokensOption', 'Survey', $oEvent->get('survey')),
            // );

            $chosen_question = $this->get('choiceQuestion', 'Survey', $sSurveyId, null);
            $chosen_question_id = $aQuestions[$chosen_question]['qid'];

            if (intval(App()->getConfig('versionnumber')) < 4) {
                $oaSubquestions = Question::model()->findAllByAttributes(array('sid' => $sSurveyId, 'parent_qid' => $chosen_question_id, 'language' => $baseLang));
            } else {
                $oaSubquestions = Question::model()->with(array('questionl10ns' => array('condition' => 'language = :language', 'params' => array(':language' => $baseLang))))->findAllByAttributes(array('parent_qid' => $chosen_question_id), array('index' => 'qid'));
            }

            $aAnswerOptions = array();

            if (intval(App()->getConfig('versionnumber')) < 4) {
                foreach ($oaSubquestions as $subquestion) {
                    if ($subquestion->attributes['language'] == $baseLang) {
                        $aAnswerOptions[$subquestion->attributes['title']] = $subquestion->attributes['question'];
                    }
                }
            } else {
                foreach ($oaSubquestions as $subquestion) {
                    $aAnswerOptions[$subquestion->title] = $subquestion->questionl10ns["{$baseLang}"]->question;
                }
            }

            $aAdditionalSettings = array();

            if ($this->get('choiceQuestion', 'Survey', $sSurveyId, null) !== '') {
                foreach ($aAnswerOptions as $answer) {
                    $aAdditionalSettings[$answer] = array(
                        'type' => 'string',
                        'label' => $answer,
                        'current' => $this->get("{$answer}", "Survey", $sSurveyId, null),
                        'help' => "Linkki kyselyyn \"{$answer}\" "
                    );
                }
                $aSettings = array_merge($aSettings, $aAdditionalSettings);
            }
        }

        $oEvent->set("surveysettings.{$this->id}", array(
            'name' => get_class($this),
            'settings' => $aSettings
        ));
    }

    public function newSurveySettings()
    {
        $oEvent = $this->event;
        foreach ($oEvent->get('settings') as $name => $value) {
            $this->set($name, $value, 'Survey', $oEvent->get('survey'));
        }
    }

    private function isApplicationSurvey($sSurveyId)
    {
        return ($this->get('bUse', 'Survey', $sSurveyId) == 1);
    }

    private function checkSurveyLink($surveyArray)
    {
        $currentLink = Yii::app()->request->url;
        $currentSurveyCode = substr($currentLink, -6);

        foreach ($surveyArray as $survey) {
            $surveyCode = substr($survey['link'], -6);
            if ($surveyCode === $currentSurveyCode) {
                return true;
            }
        }
        return false;
    }

    private function nextSurvey(array $surveyArray, $name, $surname, $lang, $token)
    {
        $oEvent = $this->event;
        $sSurveyId = $oEvent->get('surveyId');
        $sNextSurvey = '';

        if ($this->isApplicationSurvey($sSurveyId)) { //If current survey is Application survey
            $nextSurveyID = substr($surveyArray[array_keys($surveyArray)[0]]['link'], -6); //Next survey's ID

            $oSurvey = Survey::model()->findByPk($nextSurveyID);
            if (!$oSurvey) {
                throw new CHttpException(404, gT("This survey does not exist. The survey chain requires updating"));
            }

            $nextSurveyLanguages = $oSurvey->getAllLanguages();

            if (!in_array($lang, $nextSurveyLanguages)) {
                $lang = $nextSurveyLanguages[0]; //Sets survey language to be the base language if the language used in the Application survey is not found it the next one
            }

            //Basic information for the participant
            $aParticipantData = array(
                'firstname' => "{$name}",
                'lastname' => "{$surname}",
                'language' => "{$lang}",
            );

            // if ($this->get('tokensOption', 'Survey', $hakemuskys) == 1) {
            //     //Generating random token
            //     $oGeneratedToken = Token::create($nextSurveyID);
            //     $oGeneratedToken->generateToken();
            //     $aParticipantData['token'] = $oGeneratedToken->token; //Adding to participants information
            // } else { //If only one token is used throughout the whole survey chain
            $aParticipantData['token'] = $token;
            //}
            //Generated token's ID
            $tokenId = TokenDynamic::model($nextSurveyID)->insertParticipant($aParticipantData);

            //Next survey's link which includes also generated token as a parameter
            $sNextSurvey = $surveyArray[array_keys($surveyArray)[0]]['link'] . "?lang=" . $lang . "&newtest=Y&token=" . $aParticipantData['token'];

            header("Access-Control-Allow-Origin: *");
            if (Yii::app()->request->getParam('ajax') == 'on') {
                header("X-Redirect: " . $sNextSurvey);
            } else {
                header("Location: " . $sNextSurvey);
            }
            $b = Template::getLastInstance();
            Yii::app()->twigRenderer->renderHtmlPage($sNextSurvey, $b);
        } else {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $search = $protocol . '://' . $_SERVER['SERVER_NAME'] . Yii::app()->request->getRequestUri();

            $sNextSurvey = current($surveyArray);

            $currentKey = null;
            foreach ($surveyArray as $n => $c) {
                if ($c['link'] == $search) {
                    $currentKey = $n;
                    break;
                }
            }
            if ($currentKey !== null) {
                $keys = array_keys($surveyArray);
                $currentKeyIndex = array_search($currentKey, $keys);
                if ($currentKeyIndex + 1 < count($keys)) { //If the next link/survey exists
                    $nextKey = $keys[$currentKeyIndex + 1]; //Next key in the array

                    $nextSurveyID = substr($surveyArray[$nextKey]['link'], -6); //ID of the next survey

                    $oSurvey = Survey::model()->findByPk($nextSurveyID);

                    if (!$oSurvey) {
                        throw new CHttpException(404, gT("This survey does not exist"));
                    }

                    $nextSurveyLanguages = $oSurvey->getAllLanguages();

                    if (!in_array($lang, $nextSurveyLanguages)) {
                        $lang = $nextSurveyLanguages[0]; //Sets survey language to be the base language if the language used in the Application survey is not found it the next one
                    }

                    $sNextSurvey = $surveyArray[$nextKey]['link'] . "?lang=" . $lang . "&newtest=Y"; //Ready link to the next survey (yet without a token)

                    //Basic information for the participant
                    $aParticipantData = array(
                        'firstname' => "{$name}",
                        'lastname' => "{$surname}",
                        'language' => "{$lang}",
                    );

                    // if ($this->get('tokensOption', 'Survey', $hakemuskys) == 1) {
                    //     //Generating random token
                    //     $oGeneratedToken = Token::create($nextSurveyID);
                    //     $oGeneratedToken->generateToken();
                    //     $aParticipantData['token'] = $oGeneratedToken->token; //Adding to participants information
                    // } else { //If only one token is used throughout the whole survey chain
                    $aParticipantData['token'] = $token;
                    //}

                    //Generated token's ID
                    $tokenId = TokenDynamic::model($nextSurveyID)->insertParticipant($aParticipantData);

                    $sNextSurvey = $sNextSurvey . "&token=" . $aParticipantData['token'];

                    header("Access-Control-Allow-Origin: *");
                    if (Yii::app()->request->getParam('ajax') == 'on') {
                        header("X-Redirect: " . $sNextSurvey);
                    } else {
                        header("Location: " . $sNextSurvey);
                    }
                    $b = Template::getLastInstance();
                    Yii::app()->twigRenderer->renderHtmlPage($sNextSurvey, $b);
                } /*else { //This is export at the end of the survey chain FOR THE PARTICIPANT. Left here if it is going to be needed in the future ;)
                    //EXPORT EXCEL
                    $contentToAdd = '';

                    ini_set('display_errors', 0);
                    ini_set('log_errors', 1);
                    error_reporting(E_ALL & ~E_NOTICE);

                    $file_name = "testing_responses_" . $name . "_" . $surname . ".xlsx";
                    $file_path = Yii::getPathOfAlias('kyselyketju') . "\\exports\\";

                    if (!file_exists($file_path)) {
                        if (!mkdir($file_path, 0777, true)) {
                            throw new Exception("Failed to create exports folder.");
                        }
                    }

                    $file_path_final = $file_path . $file_name;

                    $writer = new XLSXWriter();
                    $writer->setAuthor('LS');

                    //Getting questions and responses as texts instead of codes
                    $testResponses = SurveyDynamic::model($hakemuskys)->findByAttributes(array('token' => $token));

                    $printAnswers = getFullResponseTable($hakemuskys, $testResponses['id'], $lang);

                    //Deleting HTML tags
                    foreach ($printAnswers as &$subArray) {
                        $subArray = array_map('strip_tags', $subArray);
                    }

                    $newApplicationResponse = array();
                    foreach ($printAnswers as $key => $value) {
                        if ($value[1]) {
                            $newApplicationResponse[$value[1] . ' ' . $value[0]] = $value[2];
                        } else {
                            $newApplicationResponse[$value[0]] = $value[2]; // valmis editoitu jokainen vastaus
                        }
                    }

                    if (!empty($newApplicationResponse)) {
                        $header = array_keys($newApplicationResponse);
                        $writer->writeSheetRow('Sheet1', $header, array(
                            'font-style' => 'bold', 'fill' => '#70cc68'
                        ));
                        $writer->writeSheetRow('Sheet1', array_values($newApplicationResponse));
                    } else {
                        $contentToAdd .= 'VIRHE';
                        $contentToAdd .= '<pre>' . var_dump($newApplicationResponse) . '</pre>';
                        $oEvent->getContent($this)->addContent($contentToAdd);
                    }

                    foreach ($surveyArray as $key => $value) {
                        $testlink = $value['link'];
                        $testsurvey_id = substr($testlink, -6);

                        $matchingResponse = SurveyDynamic::model($testsurvey_id)->findByAttributes(array('token' => $token));

                        $survey_lang = $matchingResponse->attributes['startlanguage'];

                        $printAnswersForEach = getFullResponseTable($testsurvey_id, $matchingResponse->attributes['id'], $lang);

                        //Deleting HTML tags
                        foreach ($printAnswersForEach as &$subArray) {
                            $subArray = array_map('strip_tags', $subArray);
                        }
                        
                        $newApplicationResponseForEach = array();
                        foreach ($printAnswersForEach as $key => $value) {
                            if ($value[1]) {
                                $newApplicationResponseForEach[$value[1] . ' ' . $value[0]] = $value[2];
                            } else {
                                $newApplicationResponseForEach[$value[0]] = $value[2]; // valmis jokainen vastaus
                            }
                        }

                        $survey_title = SurveyLanguageSetting::model()->findByAttributes(array('surveyls_survey_id' => $testsurvey_id, 'surveyls_language' => $survey_lang))->surveyls_title;

                        $writer->writeSheetRow(
                            'Sheet1',
                            [$survey_title],
                            array('font-style' => 'bold', 'fill' => '#70cc68')
                        );

                        $header = array_keys($newApplicationResponseForEach);

                        $writer->writeSheetRow(
                            'Sheet1',
                            $header,
                            array('font-style' => 'bold', 'fill' => '#E1E1E1')
                        );
                        $writer->writeSheetRow('Sheet1', array_values($newApplicationResponseForEach));
                    }
                    $writer->writeToFile($file_path_final);

                    $testingUrl = Yii::app()->baseUrl;
                    $base_url = "http://" . $_SERVER['HTTP_HOST'];
                    $file_url = $base_url . $testingUrl . '/plugins/kyselyketju/exports/' . $file_name;

                    $contentToAdd .= '<a href="' . $file_url . '" download>Talenna vastauksesi</a>';

                    $oEvent->getContent($this)->addContent($contentToAdd);
                }*/
            } else {
                throw new CHttpException(404, gT("Error in processing the survey links"));
            }
        }
    }


    public function afterSurveyComplete()
    {
        $oEvent = $this->event;

        $sCurrentSid = $oEvent->get('surveyId');
        $sResponseId = $oEvent->get('responseId');

        $oCurrentResponse = $this->pluginManager->getAPI()->getResponse($sCurrentSid, $sResponseId);

        $applicationSurveys = PluginSetting::model()->findAll(array('condition' => "`key`='bUse' AND `value`='\"1\"'"));

        $applicationSurveysId = array();
        foreach ($applicationSurveys as $survey) {
            $applicationSurveysId[] = $survey->model_id;
        }

        $parApplSurvey = $this->findParentApplication();

        if (!$applicationSurveysId || $parApplSurvey == null) {
            return;
        } else {
            if ($this->isApplicationSurvey($sCurrentSid)) {
                $sSurveyId = $sCurrentSid;
            } else {
                $sSurveyId = $parApplSurvey;
            }

            //This variable gets the info about the matching response with token from the DB
            $latest_response = Response::model($sSurveyId)->findAllByAttributes(array('token' => $oCurrentResponse['token']));
            $latest_response_id = $latest_response[0]->id;

            $oaQuestions = Question::model()->findAllByAttributes(array('sid' => $sSurveyId, 'type' => 'M'));

            $aQuestions = array();
            foreach ($oaQuestions as $question) {
                if ($question->type == 'M') {
                    $aQuestions[] = array('title' => $question->title, 'qid' => $question->qid);
                }
            }

            //Taking the question assigned in the settings
            $chosen_question = $this->get('choiceQuestion', 'Survey', $sSurveyId, null);
            $chosen_question_id = $aQuestions[$chosen_question]['qid'];
            $chosen_question_title = $aQuestions[$chosen_question]['title'];

            $oaSurvey = Survey::model()->findByPk($sSurveyId);
            $baseLang = $oaSurvey->language;

            if (intval(App()->getConfig('versionnumber')) < 4) {
                $oaSubquestions = Question::model()->findAllByAttributes(array('sid' => $sSurveyId, 'parent_qid' => $chosen_question_id, 'language' => $baseLang));
            } else {
                $oaSubquestions = Question::model()->with(array('questionl10ns' => array('condition' => 'language = :language', 'params' => array(':language' => $baseLang))))->findAllByAttributes(array('parent_qid' => $chosen_question_id), array('index' => 'qid'));
            }

            $aAnswerOptions = array();

            if (intval(App()->getConfig('versionnumber')) < 4) {
                foreach ($oaSubquestions as $subquestion) {
                    if ($subquestion->attributes['language'] == $baseLang) {
                        $aAnswerOptions[$subquestion->attributes['title']] = $subquestion->attributes['question'];
                    }
                }
            } else {
                foreach ($oaSubquestions as $subquestion) {
                    $aAnswerOptions[$subquestion->title] = $subquestion->questionl10ns["{$baseLang}"]->question;
                }
            }

            /* [EXAMPLE] $aAnswerOptions OUTPUTS:
            Array
            
            [SQ003] => three
            [SQ002] => two
            [SQ001] => one
            [SQ004] => four
            
            */
            $links = [];

            foreach ($aAnswerOptions as $key => $label) {
                $links[$chosen_question_title . "_" . $key] = $label;
            }

            /* [EXAMPLE] $links OUTPUTS:
            Array
            (
            [Q00_SQ003] => three
            [Q00_SQ002] => two
            [Q00_SQ001] => one
            [Q00_SQ004] => four
            )
            */

            $settingslinks = [];

            if (version_compare(phpversion(), '8.0.0') < 0) {
                foreach ($aAnswerOptions as $key => $label) {
                    $link = $this->get("{$label}", "Survey", $sSurveyId, null);
                    $settingslinks[$label] = strpos($link, '?') !== false ? substr($link, 0, -8) : $link;
                }
            } else {
                foreach ($aAnswerOptions as $key => $label) {
                    $link = $this->get("{$label}", "Survey", $sSurveyId, null);
                    $settingslinks[$label] = str_contains($link, '?') ? substr($link, 0, -8) : $link;
                }
            }

            /* [EXAMPLE] $settingslinks OUTPUTS:
            Array
            (
            [three] => http://localhost/limesurvey/index.php/435938
            [two] => http://localhost/limesurvey/index.php/825943
            [one] => http://localhost/limesurvey/index.php/136575
            [four] => http://localhost/limesurvey/index.php/489343
            )
            */

            $readylinks = [];

            foreach ($links as $key => $value) {
                $readylinks[$key] = $settingslinks[$value];
            }

            /* [EXAMPLE] $readylinks OUTPUTS:
            Array
            (
            [Q00_SQ003] => http://localhost/limesurvey/index.php/435938
            [Q00_SQ002] => http://localhost/limesurvey/index.php/825943
            [Q00_SQ001] => http://localhost/limesurvey/index.php/136575
            [Q00_SQ004] => http://localhost/limesurvey/index.php/489343
            )
            */

            ////Creating a basic array which contains of response info
            $response = $this->pluginManager->getAPI()->getResponse($sSurveyId, $latest_response_id);

            $lang = $response['startlanguage'];

            $responseVastaukset = array_filter($response, function ($key) use ($chosen_question_title) {
                return strpos($key, $chosen_question_title) === 0;
            }, ARRAY_FILTER_USE_KEY);
            /* [EXAMPLE] $responseVastaukset OUTPUTS:
            Array
            (
            [Q00_SQ001] => 
            [Q00_SQ002] => Y
            [Q00_SQ003] => Y
            [Q00_SQ004] => 
            )
            */

            $finalArray = array();

            //Adding links
            foreach ($responseVastaukset as $key => $value) {
                $finalArray[$key] = array(
                    'value' => $value,
                    'link' => $readylinks[$key]
                );
            }

            //Taking only checked response options
            $finalArray = array_filter($finalArray, function ($element) {
                return $element['value'] === 'Y';
            });

            /* [EXAMPLE] $finalArray OUTPUTS:
            Array
            (
            [Q00_SQ001] => Array
            (
            [value] => Y
            [link] => http://localhost/limesurvey/index.php/136575
            )
            [Q00_SQ003] => Array
            (
            [value] => Y
            [link] => http://localhost/limesurvey/index.php/435938
            )
            )
            */

            $token = $response['token'];

            //Name information
            $tokenId = TokenDynamic::model($sSurveyId)->findByAttributes(array('token' => $token));
            $name = $tokenId->attributes['firstname'];
            $surname = $tokenId->attributes['lastname'];

            $contentToAdd = ''; //Used for debugging

            if ($oCurrentResponse['token'] !== $response['token']) {
                return;
            }

            if ($this->isApplicationSurvey($sCurrentSid) || $this->checkSurveyLink($finalArray)) {
                $this->nextSurvey($finalArray, $name, $surname, $lang, $token);
            }

            //Debugging purposes 

            //$contentToAdd = '<pre>' . $testSearch . '</pre>';
            //$oEvent->getContent($this)->addContent($contentToAdd);
        }
    }
}
