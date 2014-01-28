<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Plain_Controller extends CI_Controller
{

    public $clean          = null;
    public $csrf_valid     = false;
    public $data           = array();
    public $db_clean       = null;
    public $flash_message  = array();
    public $footer         = 'layouts/footer';
    public $header         = 'layouts/header';
    public $html_clean     = null;
    public $limit          = 30;
    public $logged_in      = false;
    public $is_api         = false;
    public $original       = null;
    public $user_admin     = false;
    public $user_id        = 0;
    public $user_token     = 0;

    public function __construct()
    {
        // Call home
        parent::__construct();

        // Start session
        $this->sessionStart();

        // Clean incoming variables in a variety of ways
        $this->clean();

        // Get user token
        $this->getUserInfo();

        // Generate CSRF token per user
        $this->generateCSRF();

        // Get any flash messages
        $this->getFlashMessages();

    }

    // Clean any variables coming in from POST or GET 3 ways
    // We have the originals, clean and db_clean versions accessible
    protected function clean()
    {
        $method            = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'POST') ? $_POST : $_GET;
        $this->original   = new stdClass;
        $this->clean      = new stdClass;
        $this->db_clean   = new stdClass;
        $this->html_clean = new stdClass;

        if (! empty($method)) {
            foreach ($method as $k => $v) {
                $this->original->{$k}   = $v;
                $v                      = trim(decodeValue($v));
                $this->clean->{$k}      = strip_tags($v);
                $this->db_clean->{$k}   = $this->db->escape_str($this->clean->{$k});
                $this->html_clean->{$k} = $this->db->escape_str(purifyHTML($v));
            }
        }

    }

    protected function figureView($view=null, $redirect=null)
    {
        // Sort main data keys from A-Z
        ksort($this->data);

        // If api, return JSON
        if (self::isAPI() === true || self::isInternalAJAX() === true) {
            $this->renderJSON();
        }

        // If user to be redirected
        // do it
        elseif (! empty($redirect)) {
            header('Location: ' . $redirect);
            exit;
        }

        // Else return array for view
        // This will change to show view when ready
        else {

            $this->view($view);

        }
    }

    // Check and generate CRSF tokens
    protected function generateCSRF()
    {

        // IF API call, CSRF is not used
        // Set to true
        // All calls will require a user_token to validate instead
        if (self::isAPI() === true) {
            $this->csrf_valid = true;
        }
        else {

            $csrf_token = $this->session->userdata('csrf_token');
            // If set, validate it
            if (isset($this->clean->csrf_token)) {
                if (! empty($csrf_token)) {
                    $this->csrf_valid = ($csrf_token == $this->clean->csrf_token) ? true : false;
                }

                // If false, set a flash message and data error
                if ($this->csrf_valid === false) {
                    $this->setFlashMessage('We could not locate the correct security token. Please try again.');
                    $this->data['errors'] = formatErrors('Security token is invalid.', 100);
                }
            }

            // If not set, set it
            if (empty($csrf_token)) {
                $this->session->set_userdata('csrf_token', generateCSRF());
            }
        }
    }

    protected function getFlashMessages()
    {
        $flash_message = $this->session->userdata('flash_message');
        if (isset($flash_message['message']) && ! empty($flash_message['message'])) {
            $this->flash_message['type']    = $flash_message['type'];
            $this->flash_message['message'] = $flash_message['message'];
            $this->session->unset_userdata('flash_message');
        }
    }

    protected function getUserInfo()
    {
        // If the request sent the user token, or it's in the session
        // Set it
        $user_session = $this->session->userdata('user');
        if (isset($this->clean->user_token) || isset($user_session['user_token'])) {
            $this->user_token = (isset($this->clean->user_token)) ? $this->clean->user_token : $user_session['user_token'];
        }

        // If API call, get the user id
        if (self::isAPI() === true && ! empty($this->user_token) && empty($this->user_id)) {
            $this->load->model('users_model', 'user');
            $user = $this->user->read("users.user_token = '" . $this->user_token . "'", 1, 1, 'user_id, admin');
            $this->user_id    = (isset($user->user_id)) ? $user->user_id : $this->user_id;
            $this->user_admin = (isset($user->admin) && ! empty($user->admin)) ? true : $this->user_admin;
        }

        // User ID & admin
        $this->user_id    = (isset($user_session['user_id']) && ! empty($user_session['user_id'])) ? $user_session['user_id'] : $this->user_id;
        $this->user_admin = (isset($user_session['admin']) && ! empty($user_session['admin'])) ? true : $this->user_admin;
        $this->logged_in  = $this->session->userdata('logged_in');
    }

    protected function isAdmin()
    {
        return $this->user_admin;
    }

    protected function isAJAX()
    {
        return $this->input->is_ajax_request();
    }

    protected function isAPI()
    {
        return (isset($this->clean->user_token)) ? true : false;
    }

    protected function isCommandLine()
    {
        return $this->input->is_cli_request();
    }

    protected function isInternalAJAX()
    {
        return (self::isAJAX() === true && self::isSameHost() === true) ? true : false;
    }

    protected function isSameHost()
    {
        // Going to execute this better, need to think about it
        $host   = (isset($_SERVER['HTTP_HOST'])) ? strtolower($_SERVER['HTTP_HOST']) : null;
        $origin = (isset($_SERVER['HTTP_REFERER'])) ? strtolower(parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST)) : null;
        return (! empty($origin) && ! empty($host) && $host == $origin) ? true : false;
    }

    protected function isWebView()
    {
        return (self::isInternalAJAX() === false && self::isAPI() === false) ? true : false;
    }

    // If logged if invalid CSRF token is not valid
    protected function redirectIfInvalidCSRF()
    {
        if (empty($this->csrf_valid) && self::isAPI() === false) {
            header('Location: /');
            exit;
        }
    }

    // If logged in, redirect
    protected function redirectIfLoggedIn($url='/')
    {
        if (! empty($this->logged_in)) {
            header('Location: ' . $url);
            exit;
        }
    }

    // If logged out, redirect
    protected function redirectIfLoggedOut($url='/')
    {
        if (empty($this->logged_in) && empty($this->user_id)) {
            header('Location: ' . $url);
            exit;
        }
    }

    // If not an admin, redirect
    protected function redirectIfNotAdmin($url='/')
    {
        if (empty($this->user_admin)) {
            header('Location: ' . $url);
            exit;
        }
    }

    // If not an API url
    protected function redirectIfNotAPI($url='/')
    {
        if (self::isAPI() === false) {
            header('Location: ' . $url);
            exit;
        }
    }

    // Redirect if not terminal
    protected function redirectIfNotCommandLine()
    {
        if (self::isCommandLine() === false) {
            header('Location: /');
            exit;
        }
    }

    // If webview, redirect away
    protected function redirectIfWebView()
    {
        if (self::isWebView() === true) {
            header('Location: /');
            exit;
        }
    }

    protected function renderJSON()
    {
        $json         = json_encode($this->data, JSON_FORCE_OBJECT);
        $callback     = (isset($this->clean->callback)) ? $this->clean->callback : null;
        $json         = (isset($this->clean->content_type) && strtolower($this->clean->content_type) == 'jsonp') ? $callback . '(' . $json . ');' : $json;
        $content_type = (isset($this->clean->content_type) && strtolower($this->clean->content_type) == 'jsonp') ? 'application/javascript' : 'application/json';

        $this->view('json/index', array(
            'json'         => $json,
            'content_type' => $content_type,
            'no_debug'     => true,
            'no_header'    => true,
            'no_footer'    => true
        ));
    }

    // Add user info to session
    protected function sessionAddUser($user)
    {
        // Set logged in
        $this->session->set_userdata('logged_in', true);

        // Set user shiz
        $user_array = array();
        foreach ($user as $k => $v) {
            if ($k != 'password') {
                $user_array[$k] = $v;
            }
        }
        $this->session->set_userdata('user', $user_array);

        // Set user id and token
        $this->user_id    = (isset($user->user_id)) ? $user->user_id : $this->user_id;
        $this->user_token = (isset($user->user_token)) ? $user->user_token : $this->user_token;
    }

    // Clear all session variables and cookies
    protected function sessionClear()
    {
        // Remove phpsessid
        // Remove ci_session (legacy)
        // destroy session
        // set global back to empty array
        $cookie_name = $this->config->item('sess_cookie_name');
        $cookie_name = (empty($cookie_name)) ? 'PHPSESSID' : $cookie_name;
        setcookie($cookie_name, '', time()-31557600, '/', $_SERVER['HTTP_HOST']);
        setcookie('ci_session', '', time()-31557600, '/', $_SERVER['HTTP_HOST']);
        $this->session->sess_destroy();
    }

    // Start session
    protected function sessionStart()
    {
        // If request is not coming from command line & is not an API URL OR it is an internal ajax call, start the session
        if ((self::isCommandLine() === false && self::isAPI() === false) || self::isInternalAJAX() === true) {
            $this->load->library('session');
        }
    }

    protected function setFlashMessage($message, $type='error')
    {
        $this->session->set_userdata('flash_message', array('type' => $type, 'message' => $message));
    }

    // Process a view
    // This is used so that we can easily add partials to all views
    protected function view($view, $data=array())
    {
        $data                  = (empty($data)) ? $this->data : $data;
        $data['csrf_token']    = $this->session->userdata('csrf_token');
        $data['flash_message'] = $this->flash_message;
        $data['user']          = $this->session->userdata('user');
        $data['logged_in']     = $this->session->userdata('logged_in');

        // Strip tags from page_title
        if (isset($data['page_title'])) {
            $data['page_title'] = strip_tags($data['page_title']);
        }

        //If there is a header file, load it
        $header = (isset($data['header'])) ? $data['header'] : $this->header;
        if (! isset($data['no_header']) && ! isset($data['json'])) {
            $this->load->view($header, $data);
        }

        //Load main view file
        $this->load->view($view, $data);


        //If there is a footer file, load it
        $footer = (isset($data['footer'])) ? $data['footer'] : $this->footer;
        if (! isset($data['no_footer']) && ! isset($data['json'])) {
            $this->load->view($footer, $data);
        }

        //If the template is asking to debug, load it
        /*if (isset($this->clean->debug) && ((isset($_SESSION['account']['admin']) && ! empty($_SESSION['account']['admin'])) || ENVIRONMENT != 'production')) {
            $data['page_data']      = $data;
            $this->load->view('partials/internal/debug', $data);
        }*/
    }

}