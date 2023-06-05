<?php echo CHtml::beginForm(); ?>
<?php

echo "<div style='text-align: right;'>";
echo CHtml::htmlButton('<i class="fa fa-check" aria-hidden="true"></i> ' . gT('Save'), array('type' => 'submit', 'name' => 'save' . $pluginClass, 'value' => 'save', 'class' => 'btn btn-primary'));
echo " ";
echo CHtml::htmlButton('<i class="fa fa-check-circle-o " aria-hidden="true"></i> ' . gT('Save and close'), array('type' => 'submit', 'name' => 'save' . $pluginClass, 'value' => 'redirect', 'class' => 'btn btn-default'));
echo " ";

echo CHtml::link(
    gT('Close'),
    (floatval(App()->getConfig('versionnumber')) < 4) ? App()->createUrl('admin/survey', array('sa' => 'view', 'surveyid' => $surveyId)) : App()->createUrl('surveyAdministration/view', array('surveyid' => $surveyId)),
    array('class' => 'btn btn-danger')

);

echo "</div>";
?>

<div class='container-fluid'>
    <h3 class='pagetitle'>Valitse testit vastaajille</h3>
    <div class='row'>
        <div class='col-sm-12'>
            <label>
                <input type='checkbox' name='hide_completed' id='hideCompletedCheckbox' checked>
                Piilota suorittaneet
            </label>
            <table class='table' id='myTable'>
                <thead>
                    <tr>
                        <th>Token</th>
                        <th>Nimi</th>
                        <th>Sukunimi</th>
                        <th>Suorittanut</th>
                        <?php
                        $oaSurvey = Survey::model()->findByPk($surveyId);
                        $baseLang = $oaSurvey->attributes['language'];

                        $ls = SurveyLanguageSetting::model()->findByAttributes(array('surveyls_survey_id' => $surveyId, 'surveyls_language' => $baseLang));

                        $attributeNamesString = $ls->attributes['surveyls_attributecaptions'];

                        $attributeNamesNew = json_decode($attributeNamesString, true);

                        //filter and extract attributes starting with "attribute_"
                        $filteredAttributes = array_filter($attributeNamesNew, function ($key) {
                            return strpos($key, 'attribute_') === 0;
                        }, ARRAY_FILTER_USE_KEY);

                        //modify the keys to keep the "attribute_" prefix
                        $resultAtts = array_combine(
                            array_map(function ($key) {
                                return $key;
                            }, array_keys($filteredAttributes)),
                            array_map(function ($value) {
                                return trim($value, '":');
                            }, $filteredAttributes)
                        );

                        $testTokens = Token::model($surveyId)->findAll();
                        //get the attributes from the first token
                        $firstToken = $testTokens[0];
                        $firstTokenAttributes = $firstToken->attributes;

                        //count the number of attributes
                        $attributeCount = 0;
                        $attributeNames = [];
                        foreach ($firstTokenAttributes as $key => $value) {
                            if (strpos($key, 'attribute_') === 0) {
                                $attributeCount++;
                                $attributeNames[] = $key;
                            }
                        }
                        $mergedArray = array_intersect_key($resultAtts, array_flip($attributeNames));

                        $modifiedArray = array_map(function ($key, $value) {
                            return "{$key} ({$value})";
                        }, array_keys($mergedArray), $mergedArray);

                        //display modified array
                        foreach ($modifiedArray as $value) {
                            echo "<th>{$value}</th>";
                        }
                        ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    //old checked attributes
                    $checkedAttributes = array();

                    foreach ($testTokens as $token) {
                        $responseName = $token->attributes['firstname'];
                        $responseSurname = $token->attributes['lastname'];
                        $responseToken = $token->attributes['token'];
                        $responseCompleted = $token->attributes['completed'];

                        $attributes = array();
                        foreach ($token->attributes as $key => $value) {
                            if (strpos($key, 'attribute_') === 0) {
                                $attributeName = substr($key, strlen('attribute_'));
                                $attributes[] = $value;
                            }
                        }

                        //displaying response data and attributes in table rows
                        echo "<tr>";
                        echo "<td>$responseToken</td>";
                        echo "<td>$responseName</td>";
                        echo "<td>$responseSurname</td>";
                        echo "<td>$responseCompleted</td>";

                        //displaying attribute values dynamically
                        $checkedTitles = array();
                        foreach ($attributes as $index => $attribute) {
                            $isChecked = ($attribute == '1') ? 'checked' : '';
                            if ($isChecked == 'checked') {
                                $attributeKey = $attributeNames[$index];
                                $checkedTitles[] = "{$attributeKey} ({$mergedArray[$attributeKey]})";
                            }
                            //adding a unique identifier to each checkbox to use in the event listener
                            $checkboxId = "checkbox_$responseToken-$index";

                            echo "<td style='text-align: center;'><input type='checkbox' class='center-checkbox' id='$checkboxId' $isChecked><span style='display: none'>$attribute</span></td>";

                            //javaScript event listener for each checkbox
                            echo "
                            <script>
                                document.getElementById('$checkboxId').addEventListener('change', function() {
                                    var token = '$responseToken';
                                    var attributeKey = '{$attributeNames[$index]}';
                                    var columnTitle = attributeKey + ' ({$mergedArray[$attributeNames[$index]]})';
                                    var isChecked = this.checked;
                                    console.log('Token: ' + token + ', Column Title: ' + columnTitle + ', Checked: ' + isChecked);
                                    updateCheckedAttributes(token, columnTitle, isChecked);
                                });
                            </script>";
                        }
                        $checkedAttributes[$responseToken] = $checkedTitles;

                        if (empty($checkedTitles)) {
                            unset($checkedAttributes[$responseToken]);
                        }

                        echo "</tr>";
                    }
                    ?>
                    <script>
                        //function connected to the event listener
                        var updatedCheckedAttributes = {};

                        function updateCheckedAttributes(token, columnTitle, isChecked) {
                            if (typeof updatedCheckedAttributes[token] === 'undefined') {
                                updatedCheckedAttributes[token] = [];
                            }

                            if (isChecked) {
                                //adding the columnTitle to the array
                                updatedCheckedAttributes[token].push(columnTitle);
                            } else {
                                var markedColumnTitle = columnTitle + ' del'; //adding "del" marker to unchecked checkboxes
                                var index = updatedCheckedAttributes[token].indexOf(columnTitle);
                                updatedCheckedAttributes[token].push(markedColumnTitle);
                            }
                            //console.log(updatedCheckedAttributes);
                            var jsonString = JSON.stringify(updatedCheckedAttributes);
                            //console.log(jsonString);

                            //assigning the JSON string to the hidden input field's value
                            document.getElementById('hiddenFieldsContainer').value = jsonString;
                        }
                    </script>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    const hideCompletedCheckbox = document.getElementById('hideCompletedCheckbox');
    const table = document.getElementById('myTable');
    //function to handle the visibility of rows based on the checkbox state
    function handleVisibility() {
        const rows = table.getElementsByTagName('tr');

        for (let i = 0; i < rows.length; i++) {
            const cells = rows[i].getElementsByTagName('td');
            let completedCell;

            if (cells.length > 3) {
                completedCell = cells[3];

                //excluding rows with script elements from visibility logic
                const hasScriptElements = rows[i].querySelectorAll('td script').length > 0;
                if (hasScriptElements) {
                    continue;
                }

                if (hideCompletedCheckbox.checked) {
                    if (completedCell.innerText !== 'N') {
                        rows[i].style.display = 'none';
                    } else {
                        rows[i].style.display = '';
                    }
                } else {
                    rows[i].style.display = '';
                }
            }
        }
    }
    //call the handleVisibility function initially
    handleVisibility();
    //handling the visibility by the checkbox
    hideCompletedCheckbox.addEventListener('change', handleVisibility);
</script>
<?php
echo CHtml::hiddenField('checkedAttributes', json_encode($checkedAttributes));
echo CHtml::hiddenField('hiddenFieldsContainer');
echo CHtml::endForm(); ?>