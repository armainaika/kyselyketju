<?php

use \LimeSurvey\Menu\MenuItem;

$ls_xlsxwriter_path = realpath(dirname(__FILE__) . '/../../../application/third_party/xlsx_writer/xlsxwriter.class.php');
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
        'actionIndex',
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
        $event = $this->getEvent();
        $surveyId = $event->get('surveyId');

        if ($this->get('bUse', 'Survey', $surveyId) == 1) {
            $href = Yii::app()->createUrl(
                'admin/pluginhelper',
                array(
                    'sa' => 'sidebody',
                    'plugin' => 'Kyselyketju',
                    'method' => 'actionIndex',
                    'surveyId' => $surveyId,
                )
            );

            $menuItem = new MenuItem(
                array(
                    'label' => gT("Vie kyselyketjut"),
                    'iconClass' => 'fa fa-table',
                    'href' => $href
                )
            );

            $event->append('menuItems', array($menuItem));
        }
    }

    public function actionIndex($surveyId)
    {
        //kyelyketjujen vienti
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

        $filename = "testing_responses.xlsx";
        header('Content-disposition: attachment; filename="' . XLSXWriter::sanitize_filename($filename) . '"');
        header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        $writer = new XLSXWriter();
        $writer->setAuthor('LS');

        $testResponses = SurveyDynamic::model($surveyId)->findAll();

        //saadaan kyselyiden idt niiden linkeist??
        $finalArrayTest = array();
        foreach ($settingslinks as $key => $value) {
            $finalArrayTest[$key] = preg_replace('/.*\/(\d{6})$/', '$1', $value);
        }

        // $prettyResponsesTest = array();
        foreach ($testResponses as $response) {
            $sResponseId = $response->attributes['id'];
            $responseToken = $response->attributes['token'];
            $responseLang = $response->attributes['startlanguage'];

            //saadaan kysymykset ja vastaukset tekstein?? koodien sijaan
            $prettyApplicationResponse = getFullResponseTable($surveyId, $sResponseId, $responseLang); // jokainen response

            //poistetaan HTML tagit
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

            foreach ($finalArrayTest as $surveyIds) {
                $matchingResponse = SurveyDynamic::model($surveyIds)->findByAttributes(array('token' => $responseToken));
                if ($matchingResponse) {
                    //saadaan kysymykset ja vastaukset tekstein?? koodien sijaan
                    $eachResponseId = $matchingResponse->attributes['id'];
                    $eachResponseLang = $matchingResponse->attributes['startlanguage'];
                    $prettyResponseEach = getFullResponseTable($surveyIds, $eachResponseId, $eachResponseLang);
                    //poistetaan HTML tagit
                    foreach ($prettyResponseEach as &$subArray) {
                        $subArray = array_map('strip_tags', $subArray);
                    }
                    $newPrettyResponseEach = array();
                    foreach ($prettyResponseEach as $key => $value) {
                        if ($value[1]) {
                            $newPrettyResponseEach[$value[1] . ' ' . $value[0]] = $value[2];
                        } else {
                            $newPrettyResponseEach[$value[0]] = $value[2]; // valmis editoitu jokainen vastaus
                        }
                    }
                    $header = array_keys($newPrettyResponseEach);
                    $survey_title = SurveyLanguageSetting::model()->findByAttributes(array('surveyls_survey_id' => $surveyIds, 'surveyls_language' => $responseLang))->surveyls_title;
                    if (!$survey_title) {
                        $survey_title = SurveyLanguageSetting::model()->findByAttributes(array('surveyls_survey_id' => $surveyIds, 'surveyls_language' => $baseLang))->surveyls_title;
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
        $writer->writeToStdOut();
        exit(0);
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
            $sWarningQuestions = '<br/><span style="color: red;">Monivalintakysymyksi?? ei l??ytynyt!</span>';
        }

        $hakemusKyselyt = PluginSetting::model()->findAll(array('condition' => "`key`='bUse' AND `value`='\"1\"'"));

        $hakemusKyselytId = array();
        foreach ($hakemusKyselyt as $survey) {
            $hakemusKyselytId[] = $survey->model_id;
        }

        if (count($hakemusKyselytId) > 1) {
            $sWarning = '<br/><span style="color: red;">Hakemuskysely voi olla vain yksi!</span>';
        }

        $aSettings = array(
            'bUse' => array(
                'type' => 'select',
                'label' => 'Onko t??m?? Hakemus -kysely?',
                'options' => array(
                    0 => gT("No"),
                    1 => gT("Yes")
                ),
                'default' => 0,
                'help' => 'Jos t??m?? on Hakemus kysely, valitse "Kyll??"' . $sWarning,
                'current' => $this->get('bUse', 'Survey', $oEvent->get('survey')),
            ),
        );

        if ($this->get('bUse', 'Survey', $oEvent->get('survey')) == 1) {
            $aSettings['infoUse'] = array(
                'type' => 'info',
                'content' => '<h3><b>Miten saa kyselyketjun toimimaan parhaiten?</b></h3> <br/>1) Varmista, ett?? alhaalla liitetyiss?? linkeiss?? ei ole liiallisia v??lily??ntej?? alussa. Linkiss?? voi olla tai voi puuttuakin se "?lang=xx" osa, t??rkeint?? on ett?? ei ole mit????n muita parameja<br/>2) Varmista, ett?? on olemassa vain yksi Hakemuskysely. T??ll?? hetkell?? plugin ei hyv??ksy enemp???? kuin yhden Hakemuskyselyn. Jos niit?? on enemm??n niin plugin ei toimi ja tulee joko virhe Hakemuskyselyn j??lkeen tai t????ll?? asetuksissa punaisena tekstin?? varoitus<br/>3) Jos kyselyketjun aikana tulevassa kyselyss?? ei ole samaa aloituskielt??, kysely k??ynnistyy peruskielell????n',
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
            //     'help' => 'Jos t??h??n kyselyyn on luotu osallistujien lista tunnuksineen ja haluat ett?? kaikissa kyselyiss?? on yksi sama tunnus, valitse ensimm??inen vaihtoehto. Jos haluat automaattisesti generoidut tunnukset kyselyketjussa, valitse toinen vaihtoehto',
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

    private function nextSurvey(array $surveyArray, $name, $surname, $lang, $token, $hakemuskys)
    {
        $oEvent = $this->getEvent();
        $sSurveyId = $oEvent->get('surveyId');
        $sNextSurvey = '';

        if ($this->isApplicationSurvey($sSurveyId)) { // Jos t??m??nhetkinen kysely on se hakemus kysely
            $nextSurveyID = substr($surveyArray[array_keys($surveyArray)[0]]['link'], -6); // seuraavan kyselyn id

            $oSurvey = Survey::model()->findByPk($nextSurveyID);
            if (!$oSurvey) {
                throw new CHttpException(404, gT("This survey does not exist"));
            }

            $nextSurveyLanguages = $oSurvey->getAllLanguages();

            if (!in_array($lang, $nextSurveyLanguages)) {
                $lang = $nextSurveyLanguages[0]; // laittaa kyselyn kieleksi base language jos hakemuksessa k??ytetty?? kielt?? ei ole seuraavassa kyselyss??
            }

            //perustiedot
            $aParticipantData = array(
                'firstname' => "{$name}",
                'lastname' => "{$surname}",
                'language' => "{$lang}",
            );

            // if ($this->get('tokensOption', 'Survey', $hakemuskys) == 1) {
            //     //random tokenin luonti
            //     $oGeneratedToken = Token::create($nextSurveyID);
            //     $oGeneratedToken->generateToken();
            //     $aParticipantData['token'] = $oGeneratedToken->token; // lis??t????n osallistujan tietoihin
            // } else { //jos halutaan vain yksi token koko kyselyketjussa
            $aParticipantData['token'] = $token;
            //}
            //luodun tokenin id
            $tokenId = TokenDynamic::model($nextSurveyID)->insertParticipant($aParticipantData);

            // seuraavan kyselyn linkki joka sis??lt???? parametreina my??s luotun tokenin
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
            $search = 'http://' . $_SERVER['HTTP_HOST'] . Yii::app()->request->getRequestUri();

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
                if ($currentKeyIndex + 1 < count($keys)) { // jos on olemassa seuraava linkki/kysely
                    $nextKey = $keys[$currentKeyIndex + 1]; // seuraava key arrayssa

                    $nextSurveyID = substr($surveyArray[$nextKey]['link'], -6); // seuraavan kyselyn id

                    $oSurvey = Survey::model()->findByPk($nextSurveyID);

                    if (!$oSurvey) {
                        throw new CHttpException(404, gT("This survey does not exist"));
                    }

                    $nextSurveyLanguages = $oSurvey->getAllLanguages();

                    if (!in_array($lang, $nextSurveyLanguages)) {
                        $lang = $nextSurveyLanguages[0]; // laittaa kyselyn kieleksi base language jos hakemuksessa k??ytetty?? kielt?? ei ole seuraavassa kyselyss??
                    }

                    $sNextSurvey = $surveyArray[$nextKey]['link'] . "?lang=" . $lang . "&newtest=Y"; // valmis linkki seuraavaan kyselyyn (viel?? ilman tokenia)

                    //perustiedot osallistujalle
                    $aParticipantData = array(
                        'firstname' => "{$name}",
                        'lastname' => "{$surname}",
                        'language' => "{$lang}",
                    );

                    // if ($this->get('tokensOption', 'Survey', $hakemuskys) == 1) {
                    //     //random tokenin luonti
                    //     $oGeneratedToken = Token::create($nextSurveyID);
                    //     $oGeneratedToken->generateToken();
                    //     $aParticipantData['token'] = $oGeneratedToken->token; // lis??t????n osallistujan tietoihin
                    // } else { //jos halutaan vain yksi token koko kyselyketjussa
                    $aParticipantData['token'] = $token;
                    //}

                    //luodun tokenin id
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
                } /*else {
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

                    //saadaan kysymykset ja vastaukset tekstein?? koodien sijaan
                    $testResponses = SurveyDynamic::model($hakemuskys)->findByAttributes(array('token' => $token));

                    $printAnswers = getFullResponseTable($hakemuskys, $testResponses['id'], $lang);

                    //poistetaan HTML tagit
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

                        //DEBUG $contentToAdd .= '<pre>$printAnswersForEach <h3>(before the foreach loop)</h3>:<br/>' . print_r($printAnswersForEach, true) . '</pre>';
                        //poistetaan HTML tagit
                        foreach ($printAnswersForEach as &$subArray) {
                            $subArray = array_map('strip_tags', $subArray);
                        }
                        //DEBUG $contentToAdd .= '<pre>$printAnswersForEach <h3>(after the foreach loop)</h3>:<br/>' . print_r($printAnswersForEach, true) . '</pre>';

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
        $oEvent = $this->getEvent();

        $sCurrentSid = $oEvent->get('surveyId');
        $sResponseId = $oEvent->get('responseId');

        $oCurrentResponse = $this->pluginManager->getAPI()->getResponse($sCurrentSid, $sResponseId);

        $hakemusKyselyt = PluginSetting::model()->findAll(array('condition' => "`key`='bUse' AND `value`='\"1\"'"));

        $hakemusKyselytId = array();
        foreach ($hakemusKyselyt as $survey) {
            $hakemusKyselytId[] = $survey->model_id;
        }

        if (!$hakemusKyselytId) {
            return;
        } elseif (count($hakemusKyselytId) > 1) {
            $oEvent->getContent($this)->addContent("<p>Hakemuskysely voi olla vain yksi!</p>");
        } else {
            $sSurveyId = $hakemusKyselytId[0];
            // T??m?? muuttuja hakee tietokannasta tiedot viimeisen hakemus-kyselyn responsist??
            $latest_response = Response::model($sSurveyId)->findAllByAttributes(array('token' => $oCurrentResponse['token']));
            $latest_response_id = $latest_response[0]->id;

            $oaQuestions = Question::model()->findAllByAttributes(array('sid' => $sSurveyId, 'type' => 'M'));

            $aQuestions = array();
            foreach ($oaQuestions as $question) {
                if ($question->type == 'M') {
                    $aQuestions[] = array('title' => $question->title, 'qid' => $question->qid);
                }
            }

            // Otetaan asetuksissa aikaisemmin valittu kysymys
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

            /* [ESIMERKKI] $aAnswerOptions TULOSTAA:
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

            /* [ESIMERKKI] $links TULOSTAA:
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

            /* [ESIMERKKI] $settingslinks TULOSTAA:
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

            /* [ESIMERKKI] $readylinks TULOSTAA:
            Array
            (
            [Q00_SQ003] => http://localhost/limesurvey/index.php/435938
            [Q00_SQ002] => http://localhost/limesurvey/index.php/825943
            [Q00_SQ001] => http://localhost/limesurvey/index.php/136575
            [Q00_SQ004] => http://localhost/limesurvey/index.php/489343
            )
            */

            // t??m??n kautta tehd????n basic array joka sis??lt???? tiedot vastauksista //
            $response = $this->pluginManager->getAPI()->getResponse($sSurveyId, $latest_response_id);

            $lang = $response['startlanguage'];

            $responseVastaukset = array_filter($response, function ($key) use ($chosen_question_title) {
                return strpos($key, $chosen_question_title) === 0;
            }, ARRAY_FILTER_USE_KEY);
            /* [ESIMERKKI] $responseVastaukset TULOSTAA:
            Array
            (
            [Q00_SQ001] => 
            [Q00_SQ002] => Y
            [Q00_SQ003] => Y
            [Q00_SQ004] => 
            )
            */

            $finalArray = array();

            // Lis??t????n linkit
            foreach ($responseVastaukset as $key => $value) {
                $finalArray[$key] = array(
                    'value' => $value,
                    'link' => $readylinks[$key]
                );
            }

            // Otetaan vain checked vastaukset
            $finalArray = array_filter($finalArray, function ($element) {
                return $element['value'] === 'Y';
            });

            /* [ESIMERKKI] $finalArray TULOSTAA:
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

            //vanha tapa saada nimitiedot
            /*$responseNimi = array_filter($response, function ($key) {
                return strpos($key, 'name') !== false;
            }, ARRAY_FILTER_USE_KEY);

            $name = $responseNimi[array_keys($responseNimi)[0]];
            $surname = $responseNimi[array_keys($responseNimi)[1]];*/

            $token = $response['token'];

            //nimitiedot
            $tokenId = TokenDynamic::model($sSurveyId)->findByAttributes(array('token' => $token));
            $name = $tokenId->attributes['firstname'];
            $surname = $tokenId->attributes['lastname'];

            $contentToAdd = '';

            if ($oCurrentResponse['token'] !== $response['token']) {
                return;
            }

            if ($this->isApplicationSurvey($sCurrentSid) || $this->checkSurveyLink($finalArray)) {
                $this->nextSurvey($finalArray, $name, $surname, $lang, $token, $sSurveyId);
            }


            //$contentToAdd = '<pre>' . $name . ' ' . $surname . '<br/>' . print_r($tokenId, true) . '</pre>';

            $oEvent->getContent($this)->addContent($contentToAdd);
        }
    }
}
