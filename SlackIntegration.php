<?php

Class SlackIntegration extends Slack{
    private $entryPointsForInteractions = '';
    private $commands = [
        '/ply' => [
            'help' => ['func' => 'plyHelp', 'needLogin' => false, 'needConnectedProject' => false],
            'connect' => ['func' => 'plyConnect', 'needLogin' => true, 'needConnectedProject' => false],
            'manage' => ['func' => 'plyManage', 'needLogin' => true, 'needConnectedProject' => false],
            'create' => ['func' => 'plyCreateContact', 'filters' => ['filterEscapedEmails'], 'needLogin' => true, 'needConnectedProject' => true],
            'create-deal' => ['func' => 'plyCreateDeal', 'filters' => ['filterEscapedEmails'], 'needLogin' => true, 'needConnectedProject' => true],
            'view' => ['func' => 'plyView', 'filters' => ['filterEscapedEmails'], 'needLogin' => true, 'needConnectedProject' => true],
            'logout' => ['func' => 'plyLogout', 'needLogin' => true, 'needConnectedProject' => false]
        ]
    ];
    private $modals = [
        'create_contact_modal' => [
            'project' => ['type' => 'static_select'],
            'first_name' => ['type' => 'input'],
            'last_name' => ['type' => 'input'],
            'email' => ['type' => 'input'],
            'company' => ['type' => 'input'],
            'website' => ['type' => 'input'],
            'phone' => ['type' => 'input'],
            'role' => ['type' => 'input'],
            'sms_number' => ['type' => 'input'],
            'address' => ['type' => 'input'],
            'address2' => ['type' => 'input'],
            'city' => ['type' => 'input'],
            'state' => ['type  ' => 'input'],
            'zip' => ['type' => 'input'],
            'country' => ['type' => 'input'],
            'timezone' => ['type' => 'static_select']
        ]
    ];
    private $response = [];
    private $responseBlocks = [];
    private $commandParameters = [];
    private $payload = [];
    private $request = [];
    private $connectedProject = [];

    public function __construct(){
        if((isset($_POST['command']) || isset($_POST['payload'])) && $this->verifyRequest() === false){
//            $this->sendServerErrorRequest();
        }

        if(isset($_POST['command'])){
            // Slash command mode
            $this->setChannel(['id' => $_POST['channel_id'], 'name' => $_POST['channel_name']]);
            $this->setTeamId($_POST['team_id']);
            $this->setPlyCidTeamId();

            $command = $this->checkCommand();
            if($command !== false){
                // Execute command
                $this->$command();
            }else{
                $this->setResponseError();
            }
        }elseif(!empty($_POST['payload'])){
            $this->payload = json_decode($_POST['payload'], true);

            $this->setTeamId($this->payload['team']['id']);
            $this->setPlyCidTeamId();

            if ($this->payload['type'] == 'block_actions') {
                // Interaction mode
                $this->processAction();
                //$this->saveAction($_POST['payload']);
            } else if ($this->payload['type'] == 'block_suggestion') {
                // "External source" mode (use in select with type "external_select")
                $this->processSuggestion();
            } else if ($this->payload['type'] == 'view_submission') {
                // Work with the submitted data
                $this->processSubmission();
            }
        }
        $this->sendResponse();
    }

    private function checkCommand(){
        $command = dbready($_POST['command']);
        if(isset($this->commands[$command])){
            // Extract action and params
            $splitText = preg_split("/ +/", trim($_POST['text']), 2);
            $action = $splitText[0];

//            if (!isset($this->commands[$command])){
//                
//            }
            
            // TODO: check if need login to run this command

            if (!$this->getConnectedProject()){
                if ($this->commands[$command]['needConnectedProject']){
//                    $this->setResponseError();
                }
            }

            if(!empty($this->commands[$command][$action]['func'])){
                // Apply filters to command param
                if (!empty($splitText[1]) && !empty($this->commands[$command][$action]['filters'])) {
                    foreach ($this->commands[$command][$action]['filters'] as $filterMethod) {
                        if (method_exists($this, $filterMethod)) {
                            $splitText[1] = $this->$filterMethod($splitText[1]);
                        }
                    }
                }

                $this->commandParameters = $splitText;
                return $this->commands[$command][$action]['func'];
            }
        }
        return false;
    }

    private function getConnectedProject(){
        $query = dbQuery('SELECT s.pid as id, p.name FROM slack as s
            JOIN projects as p ON s.pid = p.id
            WHERE s.cid = ? AND s.team_id = ? AND s.channel_id = ? LIMIT 1',
            'iss', [$this->cid, $this->teamId, $this->channel['id']]);
        $result = $query->fetch_assoc();

        if (isset($result['id'])){
            $this->connectedProject = $result;
            return true;
        } else {
            return false;
        }
    }

    public function sendResponse(){
        header($_SERVER['SERVER_PROTOCOL'].' 200 OK', true, 200);
        header('Content-Type: application/json;charset=utf-8');

        if($this->responseBlocks){
            $this->response['blocks'] = $this->responseBlocks;
        }

        if (!empty($this->response)) {
            echo json_encode($this->response);
        }

        exit;
    }

    public function sendServerErrorRequest(){
        header($_SERVER['SERVER_PROTOCOL'].' 500 Internal Server Error', true, 500);
        exit;
    }

    private function setResponse($text, $type = 'section', $accessory = []){
        switch($type){
            case 'context';
                $response = [
                    'type' => 'context',
                    'elements' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => $text
                        ]
                    ]
                ];
                break;
            case 'section':
            default:
                $response = [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $text
                    ]
                ];
        }
        if($accessory){
            $response['accessory'] = $accessory;
        }
        $this->response['blocks'][] = $response;
    }

    private function setResponseType($responseType){
        $this->response['response_type'] = $responseType;
    }

    private function clearResponse(){
        $this->response = [];
    }

    private function setResponseError($message = ''){
        $this->response = [
            'response_type' => 'ephemeral',
            'text' => $message ? $message : 'Sorry, slash command didn\'t work. Please try again.'
        ];
    }

    private function setModalResponseErrors($errors){
        $this->response = [
            'response_action' => 'errors',
            'errors' => $errors
        ];
    }

    private function setRequestError($message){
        $this->request = [
            'response_type' => 'ephemeral',
            'text' => $message
        ];
    }

    private function getOptionsForProjectsSelect(){
        // Get platform projects
        $projects = $this->getPlyProjects();
        $options = [];
        while($project = $projects->fetch_assoc()){
            $options[] = [
                'value' => strval($project['id']),
                'text' => $project['name']
            ];
        }
        return $options;
    }
    
    private function getCurrentProjectForSelect(){
        if (empty($this->connectedProject)){
            return false;
        }

        return [
            'value' => strval($this->connectedProject['id']),
            'text' => $this->connectedProject['name']
        ];
    }

    private function getOptionsForTimezoneSelect($filterValue = ''){
        // Get timezones
        $timezones = $this->getTimezones($filterValue);
        $options = [];
        while($timezone = $timezones->fetch_assoc()){
            $options[] = [
                'value' => $timezone['zone_name'],
                'text' => [
                    'type' => 'plain_text',
                    'text' => $timezone['zone_name']
                ]
            ];
        }
        return $options;
    }

    private function getOptionsForSegmentsSelect($pid){
        $segmentsResult = $this->getSegments($this->cid, $pid);
        $options = [];
        while($segment = $segmentsResult->fetch_assoc()){
            $options[] = [
                'value' => strval($segment['id']),
                'text' => $segment['name']
            ];
        }
        return $options;
    }

    private function getOptionsForTagsSelect($pid){
        $tagsResult = $this->getTags($this->cid, $pid);
        $options = [];
        while($tag = $tagsResult->fetch_assoc()){
            $options[] = [
                'value' => strval($tag['id']),
                'text' => $tag['name']
            ];
        }
        return $options;
    }

    private function createResponseBlock($type){}

    private function createContextBlock($text = '', $imageUrl = '', $altText = ''){
        $block = [
            'type' => 'context',
            'elements' => []
        ];
        if($text){
            $block['elements'][] = [
                'type' => 'mrkdwn',
                'text' => $text
            ];
        }
        if($imageUrl){
            $block['elements'][] = [
                'type' => 'image',
                'image_url' => $imageUrl,
                'alt_text' => $altText ? $altText : ''
            ];
        }
        $this->addResponseBlock($block);
    }

    //https://api.slack.com/reference/block-kit/blocks#section
    /**
     *
     * @param string $text Maximum length is 3000 characters.
     * @param array $fields Maximum number of items is 10
     * @param array $accessory
     */
    private function createSectionBlock($text = '', $fields = [], $accessory = []){
        $block = [
            'type' => 'section'
        ];
        if($text){
            $block['text'] = [
                'type' => 'mrkdwn',
                'text' => $text
            ];
        }
        if($fields){
            $block['fields'] = $fields;
        }
        if($accessory){
            $block['accessory'] = $accessory;
        }
        $this->addResponseBlock($block);
    }

    private function createActionsBlock($elements){
        $block = [
            'type' => 'actions',
            'elements' => isset($elements[0]) ? $elements : [$elements]
        ];
        $this->addResponseBlock($block);
    }

    private function createDividerBlock(){
        $block = [
            'type' => 'divider'
        ];
        $this->addResponseBlock($block);
    }

    //https://api.slack.com/reference/block-kit/blocks#header
    private function createHeaderBlock($text){
        $block = [
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => $text
            ]
        ];
        $this->addResponseBlock($block);
    }

    private function createModalHeaderBlock($text){
        return [
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => $text
            ]
        ];
    }

    private function createModalSectionBlock($text = '', $fields = [], $accessory = [], $block_id = null){
        $block = [
            'type' => 'section'
        ];

        if($text){
            $block['text'] = [
                'type' => 'mrkdwn',
                'text' => $text
            ];
        }
        if($fields){
            $block['fields'] = $fields;
        }
        if($accessory){
            $block['accessory'] = $accessory;
        }
        if ($block_id !== null){
            $block['block_id'] = $block_id;
        }

        return $block;
    }

    private function createModalInputBlock($label, $element, $optional = false, $block_id = null){
        $block = [
            'type' => 'input',
            'label' => [
                'type' => 'plain_text',
                'text' => $label
            ],
            'element' => $element,
            'optional' => $optional
        ];

        if ($block_id !== null){
            $block['block_id'] = $block_id;
        }

        return $block;
    }

    private function createModalActionsBlock($elements, $block_id = null){
        $block = [
            'type' => 'actions',
            'elements' => $elements
        ];
        if (!is_null($block_id)){
            $block['block_id'] = $block_id;
        }
        return $block;
    }

    private function createModalDividerBlock(){
        return [
            'type' => 'divider'
        ];
    }

    private function createElementInput($action_id, $placeholder = null, $initial_value = null){
        $elementInput = [
            'type' => 'plain_text_input',
            'action_id' => $action_id
        ];

        // Placeholder
        if (!is_null($placeholder)) {
            $elementInput['placeholder'] = [
                'type' => 'plain_text',
                'text' => $placeholder
            ];
        }

        // Initial value
        if (!is_null($initial_value)) {
            $elementInput['initial_value'] = $initial_value;
        }

        return $elementInput;
    }

    /**
     * @param string $type
     * @param string $action_id
     * @param string $placeholder
     * @param array $options must have keys: text and value
     * @param array $initial_value must have keys: text and value
     * @return array
     */
    private function createElementSelect($type, $action_id, $placeholder = '', $options = [], $initial_value = null){
        $element = [
            'type' => $type,
            'action_id' => $action_id,
            'placeholder' => [
                'type' => 'plain_text',
                'text' => $placeholder ? $placeholder : 'Select an item'
            ],
        ];

        // TODO: for static_select, external_select params $options and $initial_value should have final format

        switch($type){
            case 'channels_select':
                $element['placeholder']['text'] = $placeholder ? $placeholder : 'Select a channel';
                if ($initial_value !== null){
                    $element['initial_channel'] = $initial_value;
                }
                break;
            case 'users_select':
                $element['placeholder']['text'] = $placeholder ? $placeholder : 'Select a user';
                break;
            case 'conversations_select':
                $element['placeholder']['text'] = $placeholder ? $placeholder : 'Select a conversation';
                break;
            case 'static_select':
            case 'external_select':
                $element['options'] = [];
                if($options){
                    foreach($options as $option){
                        $element['options'][] = [
                            'text' => [
                                'type' => 'plain_text',
                                'text' => $option['text']
                            ],
                            'value' => $option['value']
                        ];
                    }
                    if ($initial_value !== null){
                        $element['initial_option'] = [
                            'text' => [
                                'type' => 'plain_text',
                                'text' => $initial_value['text']
                            ],
                            'value' => $initial_value['value']
                        ];
                    }
                }
                break;
            case 'multi_static_select':
            case 'multi_external_select':
                if ($initial_value !== null){
                    $element['initial_options'] = $initial_value;
                }
                break;
        }
        return $element;
    }

    private function createElementButton($text, $action_id, $value = '', $style = ''){
        $element = [
            'type' => 'button',
            'text' => [
                'type' => 'plain_text',
                'text' => $text
            ],
            'action_id' => $action_id
        ];
        if($value){
            $element['value'] = $value;
        }
        if($style === 'primary' || $style === 'danger'){
            $element['style'] = $style;
        }
        return $element;
    }

    private function createElementDatepicker($action_id, $placeholder = null, $initial_date = ''){
        $element = [
            'type' => 'datepicker',
            'action_id' => $action_id
        ];
        // Placeholder
        if ($placeholder !== null) {
            $element['placeholder'] = [
                'type' => 'plain_text',
                'text' => $placeholder
            ];
        }
        // Initial date
        if ($initial_date != '') {
            $element['initial_date'] = $initial_date;
        }
        return $element;
    }

    private function createModal($modalTitle, $modalBlocks, $modalButtons, $callback_id = null, $private_metadata = null){
        $modalArray = [
            'type' => 'modal',
            'title' => [
                'type' => 'plain_text',
                'text' => $modalTitle,
                'emoji' => true
            ],
            'blocks' => $modalBlocks
        ];

        // Add callback_id
        if ($callback_id !== null){
            $modalArray['callback_id'] = $callback_id;
        }

        // Add buttons
        $buttonTmpl = [
            'type' => 'plain_text',
            'text' => 'Text',
            'emoji' => true
        ];
        foreach($modalButtons as $buttonKey => $buttonText){
            $newButton = $buttonTmpl;
            $newButton['text'] = $buttonText;
            $modalArray[$buttonKey] = $newButton;
        }

        // Add private_metadata
        if ($private_metadata !== null){
            $modalArray['private_metadata'] = $private_metadata;
        }

        return $modalArray;
    }

    private function addResponseBlock($block){
        $this->responseBlocks[] = $block;
    }

    /*
     * Slack commands
     */

    private function plyHelp(){
        $text = "Here are the commands that I understand:\n";
        $text .= "`/ply connect`: Connect PLY project to the Slack channel\n";
        $text .= "`/ply manage`: Manage connections of the current channel with PLY projects\n";
        $text .= "`/ply create [email]`: Create a new contact\n";
        $text .= "`/ply create-deal [email OR text]`: Create a new deal\n";
        $text .= "`/ply view [email]`: Show CRM data of contact\n";
        $text .= "`/ply help`: Show this help information\n";
        $text .= "`/ply logout`: Log out of the Platform app\n";
        $this->createSectionBlock($text);
    }

    private function plyConnect(){
        $this->createSectionBlock(':electric_plug: Connect your project.');

        $company = $this->getCompanyInfo();
        $this->createContextBlock('Logged in as <mailto:'.$company['email'].'|'.$company['email'].'>');

        // Create project select
        $projectsOptions = $this->getOptionsForProjectsSelect();
        $projectsSelect = $this->createElementSelect('static_select', 'project_select', 'Select a project', $projectsOptions);

        // Create channel select
        $channelsSelect = $this->createElementSelect('channels_select', 'channels_select', '', [], $_POST['channel_id']);

        // Create Connect button
        $btn = $this->createElementButton('Connect', 'connect_btn', '','primary');

        // Add elements to response
        $this->createActionsBlock([$projectsSelect, $channelsSelect, $btn]);
    }

    private function plyCreateContact(){
        // Verify email param if present
        if (!empty($this->commandParameters[1])) {
            if (!filter_var($this->commandParameters[1], FILTER_VALIDATE_EMAIL)){
                $this->setResponseError('This is not an email');
                return ;
            }
            $email = $this->commandParameters[1];
        } else {
            $email = null;
        }

        // Check if contact with this email already exists
        if (!is_null($email)) {
            $contact = $this->getContactInfoByEmail($email);
            if ($contact) {
                $this->setResponseError('Contact with this email already exists');
                return;
            }
        }

        // Creating modal
        $modalTitle = 'Create PLY contact';

        $projectsOptions = $this->getOptionsForProjectsSelect();
        $projectsSelectElement = $this->createElementSelect('static_select', 'project_select', 'Select a project', $projectsOptions);
        $projectsSelectBlock = $this->createModalInputBlock('Project', $projectsSelectElement, false, 'project_block');

        // 1) Contact info
        $contactInfoHeaderBlock = $this->createModalHeaderBlock('Contact info');

        $firstnameInputElement = $this->createElementInput('first_name_input', 'Enter first name');
        $firstnameInputBlock = $this->createModalInputBlock('First name', $firstnameInputElement, true, 'first_name_block');

        $lastnameInputElement = $this->createElementInput('last_name_input', 'Enter last name');
        $lastnameInputBlock = $this->createModalInputBlock('Last name', $lastnameInputElement, true, 'last_name_block');

        $emailInputElement = $this->createElementInput('email_input', 'Enter email address', $email);
        $emailInputBlock = $this->createModalInputBlock('Email', $emailInputElement, false, 'email_block');

        $companyInputElement = $this->createElementInput('company_input', 'Enter organization');
        $companyInputBlock = $this->createModalInputBlock('Organization', $companyInputElement, true, 'company_block');

        $websiteInputElement = $this->createElementInput('website_input', 'Enter website');
        $websiteInputBlock = $this->createModalInputBlock('Website', $websiteInputElement, true, 'website_block');

        $phoneInputElement = $this->createElementInput('phone_input', 'Enter phone');
        $phoneInputBlock = $this->createModalInputBlock('Phone', $phoneInputElement, true, 'phone_block');

        $roleInputElement = $this->createElementInput('role_input', 'Enter organization role');
        $roleInputBlock = $this->createModalInputBlock('Role', $roleInputElement, true, 'role_block');

        $smsNumberInputElement = $this->createElementInput('sms_number_input', 'Enter SMS Number');
        $smsNumberInputBlock = $this->createModalInputBlock('SMS Number', $smsNumberInputElement, true, 'sms_number_block');

        $dividerBlock = $this->createModalDividerBlock();

        // 2) Location info
        $locationInfoHeaderBlock = $this->createModalHeaderBlock('Location info');

        $addressInputElement = $this->createElementInput('address_input');
        $addressInputBlock = $this->createModalInputBlock('Address', $addressInputElement, true, 'address_block');

        $address2InputElement = $this->createElementInput('address2_input');
        $address2InputBlock = $this->createModalInputBlock('Address2', $address2InputElement, true, 'address2_block');

        $cityInputElement = $this->createElementInput('city_input');
        $cityInputBlock = $this->createModalInputBlock('City', $cityInputElement, true, 'city_block');

        $stateInputElement = $this->createElementInput('state_input');
        $stateInputBlock = $this->createModalInputBlock('State', $stateInputElement, true, 'state_block');

        $zipInputElement = $this->createElementInput('zip_input');
        $zipInputBlock = $this->createModalInputBlock('Zip', $zipInputElement, true, 'zip_block');

        $countryInputElement = $this->createElementInput('country_input');
        $countryInputBlock = $this->createModalInputBlock('Country', $countryInputElement, true, 'country_block');

        $timezoneSelectElement = $this->createElementSelect('external_select', 'timezone_select', 'Start typing a timezone (e.g. Europe/London)');
        $timezoneSelectBlock = $this->createModalInputBlock('Timezone', $timezoneSelectElement, true, 'timezone_block');

        $modalBlocks = [
            $projectsSelectBlock,
            $contactInfoHeaderBlock,
            $firstnameInputBlock,
            $lastnameInputBlock,
            $emailInputBlock,
            $companyInputBlock,
            $websiteInputBlock,
            $phoneInputBlock,
            $roleInputBlock,
            $smsNumberInputBlock,
            $dividerBlock,
            $locationInfoHeaderBlock,
            $addressInputBlock,
            $address2InputBlock,
            $cityInputBlock,
            $stateInputBlock,
            $zipInputBlock,
            $countryInputBlock,
            $timezoneSelectBlock
        ];
        $modalButtons = ['submit' => 'Save', 'close' => 'Cancel'];
        $modalCallbackId = 'create_contact_modal';
        $modal = $this->createModal($modalTitle, $modalBlocks, $modalButtons, $modalCallbackId);

        $request = [
            'trigger_id' => $_POST['trigger_id'],
            'view' => $modal
        ];

        $data = $this->sendRequest($this->apiUrl.'views.open', $this->integration['data']['access_token'], $request);

        exit;
    }

    private function plyCreateDeal(){
        $dealTitle = null;
        $contactSelectedOption = null;

        // Verify param if present
        if (!empty($this->commandParameters[1])) {
            // Check is it email
            if (filter_var($this->commandParameters[1], FILTER_VALIDATE_EMAIL)){
                // This is email
                $email = $this->commandParameters[1];

                // Get contact's info
                $contactInfo = $this->getContactInfoByEmail($email);
                if (empty($contactInfo)) {
                    $this->setResponseError('No such email connected with this project');
                    return;
                }

                // Set provided contact as selected in select of contacts
                $contactSelectedOption = [
                    [
                        'value' => $email,
                        'text' => [
                            'type' => 'plain_text',
                            'text' => trim($contactInfo['first_name'] . ' ' . $contactInfo['last_name']) . ' - ' . $contactInfo['email']
                        ]
                    ]
                ];
            } else {
                // This is title of deal
                $dealTitle = $this->commandParameters[1];
            }
        }

        $modalTitle = 'Create deal';
        $modalBlocks = $this->createDealModalBaseBlocks($contactSelectedOption, $dealTitle);

        $modalButtons = ['submit' => 'Submit', 'close' => 'Cancel'];
        $modalCallbackId = 'create_deal_modal';
        // With "block_suggestion" request Slack doesn't send channel's data, so save it to "private_metadata"
        $private_metadata = json_encode(['channel' => ['id' => $_POST['channel_id'], 'name' => $_POST['channel_name']]]);
        $modal = $this->createModal($modalTitle, $modalBlocks, $modalButtons, $modalCallbackId, $private_metadata);

        $this->request = [
            'trigger_id' => $_POST['trigger_id'],
            'view' => $modal
        ];
        $data = $this->sendRequest($this->apiUrl.'views.open', $this->integration['data']['access_token'], $this->request);

        exit;
    }

    // Creating modal
    private function createDealModalBaseBlocks($contactSelectedOption = null, $dealTitle = '', $pipelineId = null){
        // Project select
        $currentProjectOption = $this->getCurrentProjectForSelect();
        $projectsSelectElement = $this->createElementSelect('static_select', 'deal_project_select', 'Current project', [$currentProjectOption], $currentProjectOption);
        $projectsSectionBlock = $this->createModalSectionBlock('Current project', [], $projectsSelectElement, 'deal_project_block');

        // Contacts select
        $contactsSelectElement = $this->createElementSelect('multi_external_select', 'deal_contacts_select', 'Start typing contact\'s email or first name', [], $contactSelectedOption);
        $contactsSelectBlock = $this->createModalInputBlock('Contacts', $contactsSelectElement, false, 'deal_contacts_block');

        // Deal title input
        $dealTitleInputElement = $this->createElementInput('deal_title_input', 'Enter deal title', $dealTitle);
        $dealTitleInputBlock = $this->createModalInputBlock('Title', $dealTitleInputElement, false, 'deal_title_block');

        // Pipelines
        $pipelinesOptions = $this->getOptionsForPipelinesSelect();
        $pipelineSelected = null;
        foreach ($pipelinesOptions as $pipeline){
            if ($pipeline['value'] == $pipelineId){
                $pipelineSelected = $pipeline;
                break;
            }
        }
        $pipelinesSelectElement = $this->createElementSelect('static_select', 'deal_pipeline_select', 'Select a pipeline', $pipelinesOptions, $pipelineSelected);
        $pipelinesSectionBlock = $this->createModalSectionBlock('Pipeline', [], $pipelinesSelectElement, 'deal_pipeline_block');

        return [$projectsSectionBlock, $contactsSelectBlock, $dealTitleInputBlock, $pipelinesSectionBlock];
    }

    private function processActionDealPipelineSelect($action, $state){
        // Set channel field from private_metadata
        $private_metadata = json_decode($this->payload['view']['private_metadata'], true);
        $this->setChannel($private_metadata['channel']);

        // Get project that connected to channel
        if (!$this->getConnectedProject()){
            $this->setModalResponseErrors(['deal_project_block' => 'No project selected']);
            return;
        }

        // Create base blocks with prefilled elements
        $contactSelectedOptions = $state['values']['deal_contacts_block']['deal_contacts_select']['selected_options'];
        $dealTitle = $state['values']['deal_title_block']['deal_title_input']['value'];
        $pipeline_id = $action['selected_option']['value']; // $state['values']['deal_pipeline_block']['deal_pipeline_select']['selected_option']['value'];
        $modalBlocks = $this->createDealModalBaseBlocks($contactSelectedOptions, $dealTitle, $pipeline_id);

        // Stages select
        $stagesOptions = $this->getOptionsForStagesSelect($pipeline_id);

        if (empty($stagesOptions)){
            $this->setModalResponseErrors(['deal_pipeline_block' => 'No stages found by this pipeline']);
            return;
        }
        $stagesSelectElement = $this->createElementSelect('static_select', 'deal_stage_select', 'Select deal stage', $stagesOptions);
        $stagesSelectBlock = $this->createModalSectionBlock('Deal stage', [], $stagesSelectElement, 'deal_stage_block_'.$pipeline_id);
        $modalBlocks[] = $stagesSelectBlock;

        // Amount
        $amountInputElement = $this->createElementInput('deal_amount_input', 'Enter amount');
        $amountInputBlock = $this->createModalInputBlock('Amount ($)', $amountInputElement, false, 'deal_amount_block');
        $modalBlocks[] = $amountInputBlock;

        // Close date
        $closeDateDatepickerElement = $this->createElementDatepicker('deal_close_date_datepicker', 'Choose close date');
        $closeDateDatepickerBlock = $this->createModalSectionBlock('Close date', [], $closeDateDatepickerElement, 'deal_close_date_block');
        $modalBlocks[] = $closeDateDatepickerBlock;

        $modalTitle = 'Create deal';
        $modalButtons = ['submit' => 'Submit', 'close' => 'Cancel'];
        $modalCallbackId = 'create_deal_modal';
        $modal = $this->createModal($modalTitle, $modalBlocks, $modalButtons, $modalCallbackId, $this->payload['view']['private_metadata']);

        $this->request = [
            'view_id' => $this->payload['view']['id'],
            'hash' => $this->payload['view']['hash'],
            'view' => $modal
        ];
        $data = $this->sendRequest($this->apiUrl.'views.update', $this->integration['data']['access_token'], $this->request);
//        exit;
    }

    private function getOptionsForStagesSelect($pipeline_id){
        $stagesResult = $this->getPipelineStages($pipeline_id);
        if ($stagesResult->num_rows == 0){
            return [];
        }

        $stagesOptions = [];
        while($stage = $stagesResult->fetch_assoc()){
            $stagesOptions[] = [
                'value' => strval($stage['id']),
                'text' => $stage['name']
            ];
        }
        return $stagesOptions;
    }
    
    protected function processSubmissionCreateDeal(){
        $modalId = 'create_deal_modal';

        // Verify values of fields
        $errors = [];
        
        $dealContacts = $this->getModalFieldValue($modalId, 'deal_contacts');
        if (empty($dealContacts)){
            $errors['deal_contacts_block'] = 'Please set one or more contacts';
        }

        $dealTitle = $this->getModalFieldValue($modalId, 'deal_title');
        if (empty($dealTitle)){
            $errors['deal_title_block'] = 'Please set a title';
        }

        $dealPipeline = $this->getModalFieldValue($modalId, 'deal_pipeline');
        if (empty($dealPipeline)){
            $errors['deal_pipeline_block'] = 'Please choose a pipeline';
        }

        $dealStage = $this->getModalFieldValue($modalId, 'deal_stage');
        if (empty($dealStage)){
            $errors['deal_stage_block'] = 'Please choose a stage';
        }

        $dealAmount = $this->getModalFieldValue($modalId, 'deal_amount');
        if (empty($dealAmount)){
            $errors['deal_amount_block'] = 'Please set an amount';
        } else if (!is_numeric($dealAmount)){
            $errors['deal_amount_block'] = 'Amount should be a number';
        }

        $dealCloseDate = $this->getModalFieldValue($modalId, 'deal_close_date');
        if (empty($dealCloseDate)){
            $errors['deal_close_date_block'] = 'Please choose a close date';
        }


        if (!empty($errors)){
            $this->setModalResponseErrors($errors);
            return;
        }

        // Save deal
        
        
        // Send "204 No Content" success status response code
        header($_SERVER['SERVER_PROTOCOL'].' 204 OK', true, 204);
        die;
    }

    /**
     * Get contact data by email
     */
    private function plyView(){
        if(empty($this->commandParameters[1]) || !filter_var($this->commandParameters[1], FILTER_VALIDATE_EMAIL)){
            $this->setResponseError('Missing email');
            return ;
        }

        $email = $this->commandParameters[1];
        $contact = $this->getContactInfoByEmail($email);
        if(!$contact){
            $this->setResponseError('Not found user by this email');
            return ;
        }

        // Prepare response
        $contactFields = [
            [
                'type' => 'mrkdwn',
                'text' => '*Full Name*'
            ],
            [
                'type' => 'mrkdwn',
                'text' => '*Email*'
            ],
            [
                'type' => 'plain_text',
                'text' => $contact['first_name'].' '.$contact['last_name']
            ],
            [
                'type' => 'plain_text',
                'text' => $contact['email']
            ],
        ];
        $this->createHeaderBlock('Contact Info');
        $this->createSectionBlock('', $contactFields);

        // Get deal data
        $dealsData = $this->getDeals($contact['cc_id']);

        $this->createHeaderBlock('Deals');

        if (!empty($dealsData)) {
            foreach ($dealsData as $deal) {
                $this->createSectionBlock("Name: " . $deal['deal_title'] . "\nStatus: {$deal['deal_status']}\nValue: {$deal['deal_value']}\nClose date: " . date('d M y', $deal['deal_close_date']) . "\nPipeline: {$deal['pipeline_name']}\nStage: {$deal['stage_name']}\n\n");
            }
        } else {
            $this->createSectionBlock('Deals not found');
        }
    }

    private function plyLogout(){}

    private function saveAction($data){
        $payloadData = json_decode($data);
        $values = '';
        $type = '';
        $var = [];
        foreach($payloadData['actions'] as $action){
            $values .= '(?, ?, ?, ?, ?, ?),';
            $type .= 'sssssi';
            $var = array_merge($var, [$payloadData['team']['id'], $payloadData['channel']['id'], $payloadData['response_url'], $action['action_id'], $data, time()]);
        }
        dbQuery('INSERT INTO slack_actions (`team_id`, `channel_id`, `response_url`, `action`, `data`, `date`) VALUES '.rtrim($values, ','),
            $type,
            $var);
    }

    private function processAction(){
        foreach($this->payload['actions'] as $action){
            switch ($action['action_id']) {
                case 'connect_btn':
                    $this->setChannel($this->payload['channel']);
                    $this->setResponseUrl($this->payload['response_url']);

                    $this->processActionConnect($action, $this->payload['state']);
                    $data = $this->sendRequest($this->responseUrl, $this->integration['data']['access_token'], $this->request);

                    exit;
                case 'deal_pipeline_select':
                    $this->processActionDealPipelineSelect($action, $this->payload['state']);
                    break;
                default:
                    continue;
            }
        }
    }

    private function processActionConnect($action, $state){
        // Get block_id
        $block_id = $action['block_id'];
        if (!isset($state['values'][$block_id])){
            $this->setRequestError('Connection already exists');
            return ;
        }

        // Get project's id and channel's id
        $pid = (int)$state['values'][$block_id]['project_select']['selected_option']['value'];
        $channel_id = $state['values'][$block_id]['channels_select']['selected_channel'];

        // Verify that connection is not exist
        $issetConnectionQuery = dbQuery('SELECT id FROM slack WHERE pid = ? AND channel_id = ?', 'is', [$pid, $channel_id]);
        $issetConnection = $issetConnectionQuery->fetch_assoc();
        if (isset($issetConnection['id'])){
            $this->setRequestError('Connection already exists');
            return ;
        }

        // Insert record about pid <=> channel_id connection to the "slack" table
        $inserted = dbQuery('INSERT INTO slack (`integration_id`, `cid`, `pid`, `team_id`, `channel_id`, `channel_name`, `date`) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'iiisssi',
            [$this->integration['id'], $this->cid, $pid, $this->teamId, $this->channel['id'], $this->channel['name'], time()]);

        // Process error if happened
        if (!$inserted){
            $dump = json_encode([$this->integration['id'], $this->cid, $pid, $this->teamId, $this->channel['id'], $this->channel['name'], time()]);

            $this->setRequestError('Unknown error. Make sure no such connection exists');
            return ;
        }

        // Set request body
        $this->request = [
            'replace_original' => 'true',
            'text' => 'Connected successful'
        ];
    }
    
    private function processSuggestion(){
        $action_id = $this->payload['action_id'];
        $value = $this->payload['value'];

        switch ($action_id) {
            case 'timezone_select':
                $this->response['options'] = $this->getOptionsForTimezoneSelect($value);
                break;
            case 'deal_contacts_select':
                $this->response['options'] = $this->getOptionsForDealContactsSelect($value);
                break;
            /*case 'segments_select':
                $pid = $this->getModalFieldValue('create_contact_modal', 'project');
                if (empty($pid)){
                    $this->setModalResponseErrors(['project_block' => 'No project selected', 'segments_block' => 'No project selected']);
                    return;
                }

                $this->response['options'] = $this->getOptionsForSegmentsSelect($pid, $value);
                break;*/
            default:
                continue;
        }
    }

    private function processSubmission(){
        switch ($this->payload['view']['callback_id']) {
            case 'create_contact_modal':
                $this->processSubmissionCreateContact();
                break;
            case 'create_contact_additional_modal':
                $this->processSubmissionCreateContactAdditional();
                break;
            case 'create_deal_modal':
                $this->processSubmissionCreateDeal();
                break;
        }
    }

    private function getModalFieldValue($modalId, $field, $suffix = null){
        $fieldBlockName = $field.'_block';
        // Applying suffix if present, e.g. name of deal_stage block contains pipeline's id because
        // Slack keeps options of "Stage" select after choose another pipeline and further view update
        if ($suffix !== null){
            $fieldBlockName .= '_'.$suffix;
        }

        if (!isset($this->payload['view']['state']['values'][$fieldBlockName])){
            return null;
        }

        $block = $this->payload['view']['state']['values'][$fieldBlockName];
        $fieldType = $this->modals[$modalId][$field]['type'];
        switch ($fieldType){
            case 'input':
                return $block[$field.'_input']['value'];
            case 'static_select':
            case 'external_select':
                return $block[$field.'_select']['selected_option']['value'];
            case 'multi_static_select':
            case 'multi_external_select':
                return $block[$field.'_select']['selected_options'];
            case 'datepicker':
                return $block[$field.'_datepicker']['selected_date'];
        }

        return null;
    }

    private function processSubmissionCreateContact(){
        $modalId = 'create_contact_modal';
        $ctime = time();

        // Project id
        $pid = $this->getModalFieldValue($modalId, 'project');
        if (empty($pid)){
            $this->setModalResponseErrors(['project_block' => 'No project selected']);
            return;
        }

        // Email
        // TODO: check email
        $email = $this->getModalFieldValue($modalId, 'email');
        if (empty($email)){
            $this->setModalResponseErrors(['email_block' => 'Email is required']);
            return;
        }

        // Check if contact exists in this project
        $contactExistsInProjectQuery = dbQuery('SELECT cc_id FROM contacts_info WHERE cid = ? AND pid = ? AND email = ?', 'iis', array($this->cid, $pid, $email));
        if ($contactExistsInProjectQuery->num_rows > 0){
            $this->setModalResponseErrors(['project_block' => 'Contact with provided email already exists in this project']);
            return;
        }

        // TODO: check limit for 'contacts'

        $first_name = $this->getModalFieldValue($modalId, 'first_name');
        $last_name = $this->getModalFieldValue($modalId, 'last_name');

        // Contacts info
        $contactsSqlFields = 'cid = ?, ';
        $contactsType = 'i';
        $contactsVar = [$this->cid];

        $contactsFields = $this->modals[$modalId];
        unset($contactsFields['project'], $contactsFields['email'], $contactsFields['first_name'], $contactsFields['last_name']);
        foreach ($contactsFields as $field => $fieldSettings){
            $fieldValue = $this->getModalFieldValue($modalId, $field);
            if ($fieldValue != null){
                $contactsSqlFields .= ' '.$field.' = ?,';
                $contactsType .= 's';
                $contactsVar[] = dbready($fieldValue);
            }
        }

        $contactsSqlFields .= ' date = ?, source = ?';
        $contactsType .= 'is';
        $contactsVar[] = $ctime;
        $contactsVar[] = 'slack_create_contact';

        // Check if contact already exists in some project
        $contactExistsQuery = dbQuery('SELECT cc_id FROM contacts_info WHERE cid = ? AND email = ?', 'is', array($this->cid, $email));
        if ($contactExistsQuery->num_rows > 0){
            // Contact exists in some project
            $contactExistsRow = $contactExistsQuery->fetch_assoc();
            $cc_id = $contactExistsRow['cc_id'];

            // Add contact to project

            // TODO: update contact's data if present
        } else {
            // Contact not exists, create him
            $cc_id = dbQuery('INSERT INTO contacts SET '.$contactsSqlFields, $contactsType, $contactsVar, true);
            if ($cc_id){
                dbQuery('INSERT INTO contacts_ ...');
                dbQuery('INSERT INTO contacts_ ...');
            }
        }

        // Update view
        // Segments & Tags
        $modalTitle = 'Additional information';

        $contactAddedSectionBlock = $this->createModalSectionBlock('Contact added successfully. Now you can apply segments or tags to him. This is optionally.');
        $segmentsTagsHeaderBlock = $this->createModalHeaderBlock('Segments & Tags');

        $segmentsOptions = $this->getOptionsForSegmentsSelect($pid);
        $segmentsSelectElement = $this->createElementSelect('multi_static_select', 'segments_select', '', $segmentsOptions);
        $segmentsSelectBlock = $this->createModalInputBlock('Segments', $segmentsSelectElement, true, 'segments_block');

        $tagsOptions = $this->getOptionsForTagsSelect($pid);
        $tagsSelectElement = $this->createElementSelect('multi_static_select', 'tags_select', '', $tagsOptions);
        $tagsSelectBlock = $this->createModalInputBlock('Tags', $tagsSelectElement, true, 'tags_block');

        $modalBlocks = [
            $contactAddedSectionBlock,
            $segmentsTagsHeaderBlock,
            $segmentsSelectBlock,
            $tagsSelectBlock
        ];

        // Update view
        $modalButtons = ['submit' => 'Apply', 'close' => 'Skip'];
        $modalCallbackId = 'create_contact_additional_modal';
        $private_metadata = json_encode(['cc_id' => $cc_id]);
        $modal = $this->createModal($modalTitle, $modalBlocks, $modalButtons, $modalCallbackId, $private_metadata);

        $this->response = [
            'response_action' => 'update',
            'view' => $modal
        ];
    }

    private function processSubmissionCreateContactAdditional(){
        $modalId = 'create_contact_additional_modal';

        // Get cc_id
        $private_metadata = json_decode($this->payload['view']['private_metadata'], true);
        $cc_id = $private_metadata['cc_id'];

        // Get segments
        $segmentsList = $this->getModalFieldValue($modalId, 'segments');

        if (!empty($segmentsList)){
            // Prepare segments array
            $segments = [];
            foreach ($segmentsList as $option){
                $segments[] = (int)$option['value'];
            }
            // Add segments to the contact
            if (!empty($segments)){
                segtag('segments', $segments, $cc_id, 'add', true, $this->cid);
            }
        }

        // Get tags
        $tagsList = $this->getModalFieldValue($modalId, 'tags');
        if (!empty($tagsList)){
            // Prepare tags array
            $tags = [];
            foreach ($tagsList as $option){
                $tags[] = (int)$option['value'];
            }
            // Add tags to the contact
            if (!empty($tags)){
                segtag('tags', $tags, $cc_id, 'add', true, $this->cid);
            }
        }

        // Send "204 No Content" success status response code
        header($_SERVER['SERVER_PROTOCOL'].' 204 OK', true, 204);
        die;
    }

    private function getOptionsForDealContactsSelect($search){
        $private_metadata = json_decode($this->payload['view']['private_metadata'], true);
        $this->setChannel($private_metadata['channel']);

        if (!$this->getConnectedProject()){
            return [];
        }

        $contactsOptions = [];
        $contactsResults = $this->searchContacts($search);
        while($contact = $contactsResults->fetch_assoc()){
            $contactsOptions[] = [
                'value' => $contact['email'],
                'text' => [
                    'type' => 'plain_text',
                    'text' => trim($contact['first_name'].' '.$contact['last_name']).' - '.$contact['email']
                ]
            ];
        }
        return $contactsOptions;
    }

    private function searchContacts($search){
        return dbQuery('SELECT first_name, last_name, email FROM contacts_info
            WHERE cid = ? AND pid = ? AND (CONCAT(first_name, " ", last_name) LIKE ? OR email LIKE ?) LIMIT 100',
            'iiss',
            [$this->cid, $this->connectedProject['id'], $search.'%', $search.'%']);
    }

    private function getOptionsForPipelinesSelect(){
        $pipelinesResult = $this->getPipelines();
        if ($pipelinesResult->num_rows == 0){
            return [];
        }

        $pipelinesOptions = [];
        while($pipeline = $pipelinesResult->fetch_assoc()){
            $pipelinesOptions[] = [
                'value' => strval($pipeline['id']),
                'text' => $pipeline['name']
            ];
        }
        return $pipelinesOptions;
    }

    protected function getPipelineStages($pp_id){
        return dbQuery('SELECT id, name FROM pipeline_stages WHERE pp_id = ? ORDER BY `order` ASC LIMIT 100', 'i', [$pp_id]);
    }

    protected function getPipelines(){
        return dbQuery('SELECT id, name FROM pipeline_pipelines WHERE cid = ? AND pid = ? LIMIT 100', 'ii', [$this->cid, $this->connectedProject['id']]);
    }

    protected function filterEscapedEmails($str){
        return preg_replace("/<mailto:(.+?)\|.+?>/", "$1", $str);
    }
}
