<?php
/**
 * Plugin Name: FAU-WebSSO
 * Description: Anmeldung für zentral vergebene Kennungen von Studierenden und Beschäftigten.
 * Version: 4.0.1
 * Author: Rolf v. d. Forst
 * Author URI: http://blogs.fau.de/webworking/
 * Text Domain: fau-websso
 * Network: true
 * License: GPLv2 or later
 */

/**
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

add_action('plugins_loaded', array('FAU_WebSSO', 'instance'));

register_activation_hook(__FILE__, array('FAU_WebSSO', 'activation'));

class FAU_WebSSO {

    const version = '4.0.1'; // Plugin-Version
    const option_name = '_fau_websso';
    const version_option_name = '_fau_websso_version';
    const option_group = 'fau-websso';
    const textdomain = 'fau-websso';
    const php_version = '5.3'; // Minimal erforderliche PHP-Version
    const wp_version = '4.1'; // Minimal erforderliche WordPress-Version

    private $simplesaml_autoload_error;
    
    private $current_user_can_both;
    
    protected static $instance = null;

    public static function instance() {

        if (null == self::$instance) {
            self::$instance = new self;
            self::$instance->init();
        }

        return self::$instance;
    }

    private function init() {
        $options = $this->get_options();

        load_plugin_textdomain(self::textdomain, false, sprintf('%s/languages/', dirname(plugin_basename(__FILE__))));
        
        $this->try_simplesaml_autoload();
        $this->set_current_user_can();

        add_action('init', array($this, 'update_version'));

        add_action('admin_init', array($this, 'admin_init'));

        if (is_multisite()) {
            add_action('network_admin_menu', array($this, 'network_admin_menu'));
        } else {
            add_action('admin_menu', array($this, 'admin_menu'));
        }
        
        if($this->simplesaml_autoload_error) {
            return;
        }
        
        add_filter('authenticate', array($this, 'authenticate'), 99, 3);

        add_filter('login_url', array($this, 'login_url'), 10, 2);

        add_action('wp_logout', array($this, 'simplesaml_logout'), 0);

        add_filter('wp_auth_check_same_domain', '__return_false');

        if ($options['force_websso']) {
            $this->force_websso();
        } else {
            add_action('login_enqueue_scripts', array($this, 'login_enqueue_scripts'));
            add_action('login_form', array($this, 'login_form'));
        }

        add_filter('manage_users_columns', array($this, 'users_attributes'));

        add_action('manage_users_custom_column', array($this, 'users_attributes_columns'), 10, 3);

        add_filter('is_fau_websso_active', '__return_true');
    }

    public static function activation($network_wide) {
        self::version_compare();

        if (is_multisite() && $network_wide) {
            update_site_option(self::version_option_name, self::version);
        } else {
            update_option(self::version_option_name, self::version);
        }
    }

    private static function version_compare() {
        $error = '';

        if (version_compare(PHP_VERSION, self::php_version, '<')) {
            $error = sprintf(__('Ihre PHP-Version %s ist veraltet. Bitte aktualisieren Sie mindestens auf die PHP-Version %s.', self::textdomain), PHP_VERSION, self::php_version);
        }

        if (version_compare($GLOBALS['wp_version'], self::wp_version, '<')) {
            $error = sprintf(__('Ihre Wordpress-Version %s ist veraltet. Bitte aktualisieren Sie mindestens auf die Wordpress-Version %s.', self::textdomain), $GLOBALS['wp_version'], self::wp_version);
        }

        if (!empty($error)) {
            deactivate_plugins(plugin_basename(__FILE__), false, true);
            wp_die($error);
        }
    }

    public function update_version() {
        if (is_multisite() && get_site_option(self::version_option_name, null) != self::version) {
            update_site_option(self::version_option_name, self::version);
        } elseif (!is_multisite() && get_option(self::version_option_name, null) != self::version) {
            update_option(self::version_option_name, self::version);
        }
    }

    private function get_options() {
        $defaults = array(
            'simplesaml_include' => '/simplesamlphp/lib/_autoload.php',
            'simplesaml_auth_source' => 'default-sp',
            'force_websso' => false
        );

        if (is_multisite()) {
            $options = (array) get_site_option(self::option_name);
        } else {
            $options = (array) get_option(self::option_name);
        }

        $options = wp_parse_args($options, $defaults);
        $options = array_intersect_key($options, $defaults);

        return $options;
    }

    private function set_current_user_can() {
        $user_can_both = false;
        if (is_multisite() && current_user_can('promote_users') && current_user_can('create_users')) {
            $user_can_both = true;
        }
        
        $this->current_user_can_both = $user_can_both;
    }
    
    private function try_simplesaml_autoload() {
        $error = false;
        $options = $this->get_options();
        
        if(!file_exists(WP_CONTENT_DIR . $options['simplesaml_include'])) {
            $error = __('Die Autoload-Datei konnte nicht eingebunden werden.', self::textdomain);
        }
                
        $this->simplesaml_autoload_error = $error;
    }
    
    public function authenticate($user, $user_login, $user_pass) {

        $options = $this->get_options();

        remove_action('authenticate', 'wp_authenticate_username_password', 20);

        $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

        if (!$options['force_websso'] && $action != 'websso') {
            return wp_authenticate_username_password(null, $user_login, $user_pass);
        }

        if($this->simplesaml_autoload_error) {
            return $this->simplesaml_login_error($this->simplesaml_autoload_error);
        }
        
        include_once(WP_CONTENT_DIR . $options['simplesaml_include']);
        
        $as = new SimpleSAML_Auth_Simple($options['simplesaml_auth_source']);
        
        if (!$as->isAuthenticated()) {
            $as->requireAuth();
        }

        if (is_a($user, 'WP_User')) {
            return $user;
        }
        
        $attributes = array();

        $_attributes = $as->getAttributes();

        if (!empty($_attributes)) {
            $attributes['uid'] = isset($_attributes['urn:mace:dir:attribute-def:uid'][0]) ? $_attributes['urn:mace:dir:attribute-def:uid'][0] : '';
            $attributes['mail'] = isset($_attributes['urn:mace:dir:attribute-def:mail'][0]) ? $_attributes['urn:mace:dir:attribute-def:mail'][0] : '';
            $attributes['displayName'] = isset($_attributes['urn:mace:dir:attribute-def:displayName'][0]) ? $_attributes['urn:mace:dir:attribute-def:displayName'][0] : '';            
            $attributes['eduPersonAffiliation'] = isset($_attributes['urn:mace:dir:attribute-def:eduPersonAffiliation'][0]) ? $_attributes['urn:mace:dir:attribute-def:eduPersonAffiliation'][0] : '';
            $attributes['eduPersonEntitlement'] = isset($_attributes['urn:mace:dir:attribute-def:eduPersonEntitlement'][0]) ? $_attributes['urn:mace:dir:attribute-def:eduPersonEntitlement'][0] : '';
        }

        if (empty($attributes)) {
            return $this->simplesaml_login_error(__('Die Benutzerattribute fehlen.', self::textdomain, false));
        }

        $user_login = $attributes['uid'];

        if ($user_login != substr(sanitize_user($user_login, true), 0, 60)) {
            return $this->simplesaml_login_error(__('Eingegebene Text ist nicht geeignet als Benutzername.', self::textdomain));
        }
        
        $user_email = $attributes['mail'];
        $display_name = $attributes['displayName'];
        $display_name_array = explode(' ', $attributes['displayName']);
        $first_name = array_shift($display_name_array);
        $last_name = implode(' ', $display_name_array);

        $edu_person_affiliation = $attributes['eduPersonAffiliation'];
        $edu_person_entitlement = $attributes['eduPersonEntitlement'];

        $userdata = get_user_by('login', $user_login);

        if ($userdata) {

            if ($userdata->user_email == $user_email) {
                $user = new WP_User($userdata->ID);
                update_user_meta($userdata->ID, 'edu_person_affiliation', $edu_person_affiliation);
                update_user_meta($userdata->ID, 'edu_person_entitlement', $edu_person_entitlement);
            } 
            
            else {
                return $this->simplesaml_login_error(sprintf(__('Die IdM-Benutzerdaten sind nicht im Einklang mit den Benutzerdaten der &bdquo;%s&ldquo;-Webseite.', self::textdomain), get_bloginfo('name')));
            }
            
        }
        
        else {
                        
            if (is_multisite() && (!get_site_option('registration') || get_site_option('registration') == 'none')) {
                return $this->simplesaml_login_error(__('Zurzeit ist die Benutzer-Registrierung für IdM-Benutzer nicht erlaubt.', self::textdomain));               
            }
                        
            elseif (!is_multisite() && !get_option('users_can_register')) {
                return $this->simplesaml_login_error(__('Zurzeit ist die Benutzer-Registrierung nicht erlaubt.', self::textdomain));
            }

						if (is_multisite()) {
            	switch_to_blog(1);
						}
            
            $account_data = array(
                'user_pass' => wp_generate_password(12, false),
                'user_login' => $user_login,
                'user_email' => $user_email,
                'display_name' => $display_name,
                'first_name' => $first_name,
                'last_name' => $last_name
            );

            $user_id = wp_insert_user($account_data);

            if (is_wp_error($user_id)) {
                return $this->simplesaml_login_error(__('Die Benutzer-Registrierung ist fehlgeschlagen.', self::textdomain));
            }
            
            else {
                $user = new WP_User($user_id);
                update_user_meta($user_id, 'edu_person_affiliation', $edu_person_affiliation);
                update_user_meta($user_id, 'edu_person_entitlement', $edu_person_entitlement);
            }
        }

        return $user;
    }

    public function login_url($login_url, $redirect) {
        $login_url = site_url('wp-login.php', 'login');

        if (!empty($redirect)) {
            $login_url = add_query_arg('redirect_to', urlencode($redirect), $login_url);
        }
        
        return $login_url;
    }

    public function simplesaml_logout() {
        $options = $this->get_options();

        require_once(WP_CONTENT_DIR . $options['simplesaml_include']);

        $as = new SimpleSAML_Auth_Simple($options['simplesaml_auth_source']);

        if ($as->isAuthenticated()) {
            if ($options['force_websso']) {
                $as->logout(site_url());
            }
            
            $as->logout();
        }
        
        elseif ($options['force_websso']) {
            wp_redirect(site_url());
            exit;
        }
    }

    private function simplesaml_login_error($message, $simplesaml_authenticated = true) {
        $output = '';

        $output .= sprintf('<p><strong>%1$s</strong>: %2$s</p>', __('Fehler', self::textdomain), $message);
        $output .= sprintf('<p>%s</p>', sprintf(__('Die Anmeldung auf der &bdquo;%s&ldquo;-Webseite ist fehlgeschlagen.', self::textdomain), get_bloginfo('name')));
        $output .= sprintf('<p>%s</p>', __('Sollte dennoch keine Anmeldung möglich sein, dann wenden Sie sich bitte an den Ansprechpartner der Webseite.', self::textdomain));

        if ($simplesaml_authenticated) {
            $output .= sprintf('<p><a href="%s">' . __('Single Sign-On -Abmeldung', self::textdomain) . '</a></p>', wp_logout_url());
        }
        
        $output .= $this->get_contact();

        wp_die($output);
    }

    private function get_contact() {
        global $wpdb;

        $blog_prefix = $wpdb->get_blog_prefix(get_current_blog_id());
        $users = $wpdb->get_results(
             "SELECT user_id, user_id AS ID, user_login, display_name, user_email, meta_value
             FROM $wpdb->users, $wpdb->usermeta
             WHERE {$wpdb->users}.ID = {$wpdb->usermeta}.user_id AND meta_key = '{$blog_prefix}capabilities'
             ORDER BY {$wpdb->usermeta}.user_id");

        if (empty($users)) {
            return '';
        }

        $output = sprintf('<h3>%s</h3>' . "\n", sprintf(__('Ansprechpartner und Kontakt für die &bdquo;%1$s&ldquo;-Webseite', self::textdomain), get_bloginfo('name')));

        foreach ($users as $user) {
            $roles = unserialize($user->meta_value);
            if (isset($roles['administrator'])) {
                $output .= sprintf('<p>%1$s<br/>%2$s %3$s</p>' . "\n", $user->display_name, __('E-Mail:', self::textdomain), make_clickable($user->user_email));
            }
        }

        return $output;
    }
    
    private function force_websso() {
        $this->register_redirect();
        
        if(is_admin()) {
            $this->user_new_page_redirect();
        }

        add_filter( 'wpmu_signup_user_notification', '__return_false' );
        add_filter( 'wpmu_welcome_user_notification', '__return_false' );
        
        add_action('lost_password', array($this, 'disable_function'));
        add_action('retrieve_password', array($this, 'disable_function'));
        add_action('password_reset', array($this, 'disable_function'));
        
        add_filter('show_password_fields', '__return_false');

        add_filter('show_network_site_users_add_existing_form', '__return_false');
        add_filter('show_network_site_users_add_new_form', '__return_false');
        
        add_action('network_admin_menu', array($this, 'network_admin_user_new_page'));
        add_action('admin_menu', array($this, 'admin_user_new_page'));        
    }

    public function register_redirect() {
        if ($this->is_login_page() && isset($_REQUEST['action']) && $_REQUEST['action'] == 'register') {
            wp_redirect(site_url('wp-login.php', 'login'));
            die();
        }        
     }
    
    private function user_new_page_redirect() {
        if ($this->is_user_new_page()) {
            wp_redirect('users.php?page=usernew');
            die();
        }        
    }
    
    private function is_login_page() {
        if(isset($GLOBALS['pagenow'])) {
            return in_array($GLOBALS['pagenow'], array('wp-login.php'));
        }
        return false;
    }

    private function is_user_new_page() {
        if(isset($GLOBALS['pagenow'])) {
            return in_array($GLOBALS['pagenow'], array('user-new.php'));
        }
        return false;
    }
    
    public function disable_function() {
        $output = __('Gebrauchsunfähige Funktion.', self::textdomain);
        wp_die($output);
    }
        
    public function network_admin_user_new_page() {
        global $submenu;

        remove_submenu_page('users.php', 'user-new.php');

        $submenu_page = add_submenu_page('users.php', __('Neu hinzufügen', self::textdomain), __('Neu hinzufügen', self::textdomain), 'manage_network_users', 'usernew', array($this, 'network_admin_user_new'));

        add_action(sprintf('load-%s', $submenu_page), array($this, 'network_admin_user_new_help_tab'));

        if(isset($submenu['users.php'])) {
            foreach ($submenu['users.php'] as $key => $value) {
                if ($value == __('Neu hinzufügen', self::textdomain)) {
                    break;
                }
            }
            
            $submenu['users.php'][10] = $submenu['users.php'][$key];
            unset($submenu['users.php'][$key]);

            ksort($submenu['users.php']);            
        }
        
    }

    public function network_admin_user_new_help_tab() {
        $help = '<p>' . __('Die Funktion "Benutzer hinzufügen" erstellt ein neues Benutzerkonto im Netzwerk.', self::textdomain) . '</p>';
        $help .= '<p>' . __('Benutzer, die im Netzwerk registriert sind, aber keine eigene Webseite haben, werden automatisch in der Netzwerk-Webseite hinzugefügt und haben so die Möglichkeit, ihr Profil zu bearbeiten und zu sehen, in welchen Webseiten sie noch als Abonennten geführt sind.', self::textdomain) . '</p>';

        get_current_screen()->add_help_tab(array(
            'id' => 'overview',
            'title' => __('Übersicht', self::textdomain),
            'content' => $help,
        ));

        get_current_screen()->add_help_tab(array(
            'id' => 'user-roles',
            'title' => __('Benutzerrollen', self::textdomain),
            'content' => '<p>' . __('Hier ist ein grober Überblick über die verschiedenen Benutzerrollen und die jeweils damit verknüpften Berechtigungen:', self::textdomain) . '</p>' .
            '<ul>' .
            '<li>' . __('Abonennten können nur Kommentare lesen und abgeben, aber keine eigenen Inhalte erstellen.', self::textdomain) . '</li>' .
            '<li>' . __('Mitarbeiter können eigene Artikel schreiben und bearbeiten, sie jedoch nicht veröffentlichen. Auch dürfen sie keine Dateien hochladen.', self::textdomain) . '</li>' .
            '<li>' . __('Autoren können ihre eigenen Artikel veröffentlichen und verwalten sowie Dateien hochladen.', self::textdomain) . '</li>' .            
            '<li>' . __('Redakteure können Artikel und Seiten anlegen und veröffentlichen, sowie die Artikel, Seiten, etc. von anderen Benutzern verwalten (ändern, löschen, veröffentlichen).', self::textdomain) . '</li>' .
            '<li>' . __('Administratoren haben die komplette Macht und sehen alle Optionen.', self::textdomain) . '</li>' .
            '</ul>'
        ));        
    }
    
    public function admin_user_new_page() {
        global $submenu;

        remove_submenu_page('users.php', 'user-new.php');

        if (is_multisite()) {
            $capability = 'promote_users';
        } else {
            $capability = 'create_users';
        }
        
        $submenu_page = add_submenu_page('users.php', __('Neu hinzufügen', self::textdomain), __('Neu hinzufügen', self::textdomain), $capability, 'usernew', array($this, 'admin_user_new'));

        add_action(sprintf('load-%s', $submenu_page), array($this, 'admin_user_new_help_tab'));

        if(isset($submenu['users.php'])) {
            foreach ($submenu['users.php'] as $key => $value) {
                if ($value == __('Neu hinzufügen', self::textdomain)) {
                    break;
                }
            }
            
            $submenu['users.php'][10] = $submenu['users.php'][$key];
            unset($submenu['users.php'][$key]);

            ksort($submenu['users.php']);            
        }
        
    }

    public function admin_user_new_help_tab() {
        $help = '<p>' . __('Um einen neuen Benutzer zu Ihrer Webseite hinzufügen, füllen Sie das Formular auf dieser Seite aus, und klicken Sie unten auf Neuen Benutzer hinzufügen.', self::textdomain) . '</p>';

        if (is_multisite()) {
            $help .= '<p>' . __('Da dies ein Webseitennetzwerk ist, können Sie in anderen Webseiten dieses Netzwerks existierende Benutzer einfach hinzufügen, in dem Sie deren Nutzernamen oder E-Mail-Adresse angeben, sowie deren Benutzerrolle festlegen. Für weitere Optionen müssen Sie blogübergreifender Administrator sein. Sie können dann über Netzwerkadministrator &gt; Alle Benutzer das Profil des Benutzers verändern.', self::textdomain) . '</p>' .
            '<p>' . __('Neue Benutzer bekommen eine E-Mail, in welcher ihnen mitgeteilt wird, dass sie als Benutzer dieser Website registriert wurden. Sie können allerdings die Checkbox <em>Keine E-Mail versenden</em> markieren, so dass die E-Mail nicht versendet wird.', self::textdomain) . '</p>';
        } else {
            $help .= '<p>' . __('Neue Benutzer bekommen eine E-Mail, in welcher ihnen mitgeteilt wird, dass sie als Benutzer dieser Website registriert wurden.', self::textdomain) . '</p>';
        }

        $help .= '<p>' . __('Vergessen Sie nicht, unten auf dieser Seite auf Neuen Benutzer hinzufügen zu klicken, wenn Sie fertig sind.', self::textdomain) . '</p>';

        get_current_screen()->add_help_tab(array(
            'id' => 'overview',
            'title' => __('Übersicht', self::textdomain),
            'content' => $help,
        ));

        get_current_screen()->add_help_tab(array(
            'id' => 'user-roles',
            'title' => __('Benutzerrollen', self::textdomain),
            'content' => '<p>' . __('Hier ist ein grober Überblick über die verschiedenen Benutzerrollen und die jeweils damit verknüpften Berechtigungen:', self::textdomain) . '</p>' .
            '<ul>' .
            '<li>' . __('Abonennten können nur Kommentare lesen und abgeben, aber keine eigenen Inhalte erstellen.', self::textdomain) . '</li>' .
            '<li>' . __('Mitarbeiter können eigene Artikel schreiben und bearbeiten, sie jedoch nicht veröffentlichen. Auch dürfen sie keine Dateien hochladen.', self::textdomain) . '</li>' .
            '<li>' . __('Autoren können ihre eigenen Artikel veröffentlichen und verwalten sowie Dateien hochladen.', self::textdomain) . '</li>' .            
            '<li>' . __('Redakteure können Artikel und Seiten anlegen und veröffentlichen, sowie die Artikel, Seiten, etc. von anderen Benutzern verwalten (ändern, löschen, veröffentlichen).', self::textdomain) . '</li>' .
            '<li>' . __('Administratoren haben die komplette Macht und sehen alle Optionen.', self::textdomain) . '</li>' .
            '</ul>'
        ));        
    }
    
    public function network_admin_user_new() {
        if (isset($_REQUEST['action']) && 'add-user' == $_REQUEST['action']) {
            check_admin_referer('add-user', '_wpnonce_add-user');

            if (!is_array($_POST['user'])) {
                wp_die(__('Es kann kein leerer Benutzer angelegt werden.', self::textdomain));
            }

            $user = wp_unslash($_POST['user']);

            $user_details = wpmu_validate_user_signup($user['username'], $user['email']);
            if (is_wp_error($user_details['errors']) && !empty($user_details['errors']->errors)) {
                $add_user_errors = $user_details['errors'];
            }
            
            else {
                $password = wp_generate_password(12, false);
                $user_id = wpmu_create_user(esc_html(strtolower($user['username'])), $password, sanitize_email($user['email']));

                if (!$user_id) {
                    $add_user_errors = new WP_Error('add_user_fail', __('Der Benutzer konnte nicht hinzugefügt werden.', self::textdomain));
                }
                
                else {
                    $this->new_user_notification($user_id);
                    wp_redirect(add_query_arg(array('page' => 'usernew', 'update' => 'added'), 'users.php'));
                    exit;
                }
            }
        }

        if (isset($_GET['update'])) {
            $messages = array();
            if ('added' == $_GET['update']) {
                $messages[] = __('Benutzer hinzugefügt.', self::textdomain);
            }
        }
        ?>
        <div class="wrap">
        <h2 id="add-new-user"><?php _e('Neuen Benutzer hinzufügen', self::textdomain) ?></h2>
        <?php
        if (!empty($messages)) {
            foreach ($messages as $msg) {
                printf('<div id="message" class="updated"><p>%s</p></div>', $msg);
            }
        }

        if (isset($add_user_errors) && is_wp_error($add_user_errors)) { ?>
            <div class="error">
                <?php
                    foreach ($add_user_errors->get_error_messages() as $message) {
                        echo "<p>$message</p>";
                    }
                ?>
            </div>
        <?php } ?>
            <form action="<?php echo network_admin_url('users.php?page=usernew&action=add-user'); ?>" id="adduser" method="post">
            <table class="form-table">
                <tr class="form-field form-required">
                    <th scope="row"><?php _e('Benutzerkennung (IdM)', self::textdomain) ?></th>
                    <td><input type="text" class="regular-text" name="user[username]" /></td>
                </tr>
                <tr class="form-field form-required">
                    <th scope="row"><?php _e('E-Mail-Adresse (IdM)', self::textdomain) ?></th>
                    <td><input type="text" class="regular-text" name="user[email]" /></td>
                </tr>
                <tr class="form-field">
                    <td colspan="2"><?php _e('Eine Willkommen-E-Mail mit dem entsprechenden Anmeldelink wird an die angegebene E-Mail-Adresse versandt.', self::textdomain) ?></td>
                </tr>
            </table>
            <?php wp_nonce_field( 'add-user', '_wpnonce_add-user' ); ?>
            <?php submit_button(__('Benutzer hinzufügen', self::textdomain), 'primary', 'add-user'); ?>
            </form>
        </div>
        <?php
    }
    
    public function admin_user_new() {
        global $wpdb;
        
        if (isset($_REQUEST['action']) && 'adduser' == $_REQUEST['action']) {
            check_admin_referer('add-user', '_wpnonce_add-user');

            $user_details = null;
            $user_email = wp_unslash($_REQUEST['email']);
            if (strpos( $user_email, '@') !== false) {
                $user_details = get_user_by('email', $user_email);
            }
            
            else {
                if (is_super_admin()) {
                    $user_details = get_user_by('login', $user_email);
                }
                
                else {
                    wp_redirect(add_query_arg(array('page' => 'usernew', 'update' => 'enter_email'), 'users.php'));
                    die();
                }
            }

            if (!$user_details) {
                wp_redirect(add_query_arg(array('page' => 'usernew', 'update' => 'does_not_exist'), 'users.php'));
                die();
            }

            // Bestehenden Benutzer hinzufügen
            $redirect = add_query_arg( array('page' => 'usernew'), 'users.php' );
            $username = $user_details->user_login;
            $user_id = $user_details->ID;
            
            if (($username != null && !is_super_admin($user_id)) && (array_key_exists(get_current_blog_id(), get_blogs_of_user($user_id)))) {
                $redirect = add_query_arg( array('page' => 'usernew', 'update' => 'addexisting'), 'users.php' );
            } 
            
            else {
                add_existing_user_to_blog(array('user_id' => $user_id, 'role' => $_REQUEST[ 'role' ]));
                if ( isset( $_POST['noconfirmation']) && is_super_admin()) {                   
                    $redirect = add_query_arg(array('page' => 'usernew', 'update' => 'addnoconfirmation'), 'users.php');
                } 
                
                else {
                    $this->add_existing_user_notification($user_id);
                    $redirect = add_query_arg(array('page' => 'usernew', 'update' => 'add'), 'users.php');
                }
            }
            wp_redirect($redirect);
            die();
        }
        
        elseif (isset($_REQUEST['action']) && 'createuser' == $_REQUEST['action']) {
            check_admin_referer('create-user', '_wpnonce_create-user');

            if (!is_multisite()) {
                $user_id = $this->create_user();

                if (is_wp_error($user_id)) {
                    $add_user_errors = $user_id;
                }
                
                else {                   
                    $this->new_user_notification($user_id);
                    
                    if (current_user_can('list_users')) {
                        $redirect = add_query_arg(array('update' => 'add', 'id' => $user_id), 'users.php');
                    }
                    
                    else {
                        $redirect = add_query_arg(array('page' => 'usernew', 'update' => 'add'), 'users.php');
                    }
                    wp_redirect( $redirect );
                    die();
                }
            }
            
            else {
                // Neuen Benutzer hinzufügen
                $new_user_email = wp_unslash($_REQUEST['email']);
                $user_details = wpmu_validate_user_signup($_REQUEST['user_login'], $new_user_email);
                
                if (is_wp_error($user_details['errors']) && !empty($user_details['errors']->errors)) {
                    $add_user_errors = $user_details[ 'errors' ];
                } 
                
                else {
                    $new_user_login = sanitize_user(wp_unslash($_REQUEST['user_login']), true);
                                        
                    wpmu_signup_user($new_user_login, $new_user_email, array('add_to_blog' => $wpdb->blogid, 'new_role' => $_REQUEST['role']));
                    
                    $key = $wpdb->get_var($wpdb->prepare("SELECT activation_key FROM {$wpdb->signups} WHERE user_login = %s AND user_email = %s", $new_user_login, $new_user_email));
                    wpmu_activate_signup($key);
                    
                    if (isset($_POST[ 'noconfirmation' ]) && is_super_admin()) {
                        $redirect = add_query_arg( array('page' => 'usernew', 'update' => 'addnoconfirmation'), 'users.php' );
                    }
                    
                    else {
                        $this->new_user_notification($user_id);
                        $redirect = add_query_arg( array('page' => 'usernew', 'update' => 'newuserconfirmation'), 'users.php' );
                    }
                    wp_redirect($redirect);
                    die();
                }
            }
        }

        $title = __('Neuen Benutzer hinzufügen', self::textdomain);

        $do_both = false;
        if (is_multisite() && current_user_can('promote_users') && current_user_can('create_users')) {
            $do_both = true;
        }
        
        wp_enqueue_script('wp-ajax-response');
        wp_enqueue_script('user-profile');

        if (is_multisite() && current_user_can('promote_users') && !wp_is_large_network('users') && (is_super_admin() || apply_filters('autocomplete_users_for_site_admins', false))) {
            wp_enqueue_script('user-suggest');
        }

        if (isset($_GET['update'])) {
            $messages = array();
            if (is_multisite()) {
                switch ($_GET['update']) {                   
                    case "newuserconfirmation":
                        $messages[] = __('Das Benutzerkonto wurde erfolgreich angelegt. Die Einladungs-E-Mail wurde zum neuen Benutzer versandt.', self::textdomain);
                        break;
                    case "add":
                        $messages[] = __('Der Benutzer wurde zu Ihrer Webseite hinzugefügt. Die Einladungs-E-Mail wurde zum Benutzer versandt.', self::textdomain);
                        break;                   
                    case "addnoconfirmation":
                        $messages[] = __('Der Benutzer wurde zu Ihrer Webseite hinzugefügt.', self::textdomain);
                        break;
                    case "addexisting":
                        $messages[] = __('Dieser Benutzer ist bereits ein Mitglied dieser Webseite.', self::textdomain);
                        break;
                    case "does_not_exist":
                        $messages[] = __('Der angeforderte Benutzer existiert nicht.', self::textdomain);
                        break;
                    case "enter_email":
                        $messages[] = __('Bitte geben Sie eine gültige E-Mail-Adresse ein.', self::textdomain);
                        break;
                }
            }
            
            else {
                if ('add' == $_GET['update']) {
                    $messages[] = __('Benutzer hinzugefügt.', self::textdomain);
                }
            }
        }
        ?>
        <div class="wrap">
        <h2 id="add-new-user"> <?php
        if (current_user_can('create_users')) {
            _e('Neuen Benutzer hinzufügen', self::textdomain);
        } elseif (current_user_can('promote_users')) {
            _e('Bestehenden Benutzer hinzufügen', self::textdomain);
        } ?>
        </h2>

        <?php if (isset($errors) && is_wp_error($errors)) : ?>
            <div class="error">
                <ul>
                <?php
                    foreach ($errors->get_error_messages() as $err) {
                        echo "<li>$err</li>\n";
                    }
                ?>
                </ul>
            </div>
        <?php endif;

        if (!empty($messages)) {
            foreach ($messages as $msg) {
                echo '<div id="message" class="updated"><p>' . $msg . '</p></div>';
            }
        } ?>

        <?php if (isset($add_user_errors) && is_wp_error($add_user_errors)) : ?>
            <div class="error">
                <?php
                    foreach ($add_user_errors->get_error_messages() as $message) {
                        echo "<p>$message</p>";
                    }
                ?>
            </div>
        <?php endif; ?>
        <div id="ajax-response"></div>

        <?php
        if (is_multisite()) {
            if ($do_both) {
                echo '<h3 id="add-existing-user">' . __('Bestehenden Benutzer hinzufügen', self::textdomain) . '</h3>';
            }
            if (!is_super_admin()) {
                echo '<p>' . __('Tragen Sie die E-Mail-Adresse eines bestehenden Nutzers dieses Netzwerkes ein, um ihn zu dieser Webseite einzuladen.', self::textdomain) . '</p>';
                $label = __('E-Mail-Adresse', self::textdomain);
                $type  = 'email';
            } else {
                echo '<p>' . __('Tragen Sie die E-Mail-Adresse eines bestehenden Nutzers dieses Netzwerkes ein, um ihn zu dieser Webseite einzuladen.', self::textdomain) . '</p>';
                $label = __('E-Mail-Adresse oder Benutzername', self::textdomain);
                $type  = 'text';
            }
        ?>
        <form action="" method="post" name="adduser" id="adduser" class="validate" novalidate="novalidate">
        <input name="action" type="hidden" value="adduser" />
        <?php wp_nonce_field('add-user', '_wpnonce_add-user') ?>

        <table class="form-table">
            <tr class="form-field form-required">
                <th scope="row"><label for="adduser-email"><?php echo $label; ?></label></th>
                <td><input name="email" type="<?php echo $type; ?>" id="adduser-email" class="wp-suggest-user" value="" /></td>
            </tr>
            <tr class="form-field">
                <th scope="row"><label for="adduser-role"><?php _e('Rolle', self::textdomain); ?></label></th>
                <td><select name="role" id="adduser-role">
                    <?php wp_dropdown_roles( get_option('default_role')); ?>
                    </select>
                </td>
            </tr>
            <tr class="form-field">
                <td colspan="2"><?php _e('Eine Willkommen-E-Mail mit dem entsprechenden Anmeldelink wird an die angegebene E-Mail-Adresse versandt.', self::textdomain) ?></td>
            </tr>            
            <?php if (is_super_admin()) { ?>
            <tr>
                <th scope="row"><label for="adduser-noconfirmation"><?php _e('Keine E-Mail versenden', self::textdomain) ?></label></th>
                <td><label for="adduser-noconfirmation"><input type="checkbox" name="noconfirmation" id="adduser-noconfirmation" value="1" /> <?php _e('Nutzer hinzufügen ohne eine E-Mail zu versenden.', self::textdomain); ?></label></td>
            </tr>
            <?php } ?>
        </table>
        <?php submit_button( __('Bestehenden Benutzer hinzufügen', self::textdomain), 'primary', 'adduser', true, array('id' => 'addusersub')); ?>
        </form>
        <?php
        } // is_multisite()

        if (current_user_can('create_users')) {
            if ($do_both) {
                echo '<h3 id="create-new-user">' . __('Neuen Benutzer hinzufügen', self::textdomain) . '</h3>';
            }
        ?>
        <p><?php _e('Legen Sie einen neuen Nutzer an und fügen Sie ihn dieser Website zu.', self::textdomain); ?></p>
        <form action="" method="post" name="createuser" id="createuser" class="validate" novalidate="novalidate">
        <input name="action" type="hidden" value="createuser" />
        <?php wp_nonce_field('create-user', '_wpnonce_create-user'); ?>
        <?php
        $creating = isset($_POST['createuser']);

        $new_user_login = $creating && isset($_POST['user_login']) ? wp_unslash($_POST['user_login']) : '';
        $new_user_email = $creating && isset($_POST['email']) ? wp_unslash($_POST['email']) : '';
        $new_user_role = $creating && isset($_POST['role']) ? wp_unslash($_POST['role']) : '';
        $new_user_send_password = $creating && isset($_POST['send_password']) ? wp_unslash($_POST['send_password']) : '';
        $new_user_ignore_pass = $creating && isset($_POST['noconfirmation']) ? wp_unslash($_POST['noconfirmation']) : '';
        ?>
        <table class="form-table">
            <tr class="form-field form-required">
                <th scope="row"><label for="user_login"><?php _e('Benutzerkennung (IdM)', self::textdomain); ?> <span class="description"><?php _e('(erforderlich)', self::textdomain); ?></span></label></th>
                <td><input name="user_login" type="text" id="user_login" value="<?php echo esc_attr($new_user_login); ?>" aria-required="true" /></td>
            </tr>
            <tr class="form-field form-required">
                <th scope="row"><label for="email"><?php _e('E-Mail-Adresse (IdM)', self::textdomain); ?> <span class="description"><?php _e('(erforderlich)', self::textdomain); ?></span></label></th>
                <td><input name="email" type="email" id="email" value="<?php echo esc_attr( $new_user_email ); ?>" /></td>
            </tr>
            <tr class="form-field">
                <th scope="row"><label for="role"><?php _e('Rolle', self::textdomain); ?></label></th>
                <td><select name="role" id="role">
                    <?php
                    if (!$new_user_role) {
                        $new_user_role = !empty($current_role) ? $current_role : get_option('default_role');
                    }
                    wp_dropdown_roles($new_user_role);
                    ?>
                    </select>
                </td>
            </tr>
            <tr class="form-field">
                <td colspan="2"><?php _e('Eine Willkommen-E-Mail mit dem entsprechenden Anmeldelink wird an die angegebene E-Mail-Adresse versandt.', self::textdomain) ?></td>
            </tr>
            <?php if (is_multisite() && is_super_admin()) { ?>
            <tr>
                <th scope="row"><label for="noconfirmation"><?php _e('Keine E-Mail versenden', self::textdomain) ?></label></th>
                <td><label for="noconfirmation"><input type="checkbox" name="noconfirmation" id="noconfirmation" value="1" <?php checked( $new_user_ignore_pass ); ?> /> <?php _e('Nutzer hinzufügen ohne eine E-Mail zu versenden.', self::textdomain); ?></label></td>
            </tr>
            <?php } ?>
        </table>

        <?php submit_button( __('Neuen Benutzer hinzufügen', self::textdomain), 'primary', 'createuser', true, array('id' => 'createusersub')); ?>

        </form>
        <?php } // current_user_can('create_users') ?>
        </div>
        <?php
    }

    public function network_admin_menu() {
        add_submenu_page('settings.php', __('FAU-WebSSO', self::textdomain), __('FAU-WebSSO', self::textdomain), 'manage_network_options', self::option_group, array($this, 'network_options_page'));
    }

    public function admin_menu() {
        add_options_page(__('FAU-WebSSO', self::textdomain), __('FAU-WebSSO', self::textdomain), 'manage_options', self::option_group, array($this, 'options_page'));
    }

    public function network_options_page() {
        if (!empty($_POST[self::option_name])) {
            check_admin_referer(self::option_group . '-options');
            $options = $this->get_options();
            $input = $this->options_validate($_POST[self::option_name]);
            if ($options !== $input) {
                update_site_option(self::option_name, $input);
            }
        }

        if (isset($_POST['action']) && $_POST['action'] == 'update') {
            ?><div id="message" class="updated"><p><?php _e('Einstellungen gespeichert.', self::textdomain) ?></p></div><?php
        }
        ?>
        <div class="wrap">
        <?php screen_icon('options-general'); ?>
            <h2><?php echo esc_html(__('Einstellungen &rsaquo; FAU-WebSSO', self::textdomain)); ?></h2>
            <?php if($this->simplesaml_autoload_error): ?>
            <div class="error">
                <p><?php _e($this->simplesaml_autoload_error, self::textdomain); ?></p>
            </div>
            <?php endif; ?>            
            <form method="post">
            <?php
            do_settings_sections(self::option_group);
            settings_fields(self::option_group);
            submit_button();
            ?>
            </form>
        </div>
        <?php
    }

    public function options_page() {
        ?>
        <div class="wrap">
        <?php screen_icon(); ?>
            <h2><?php echo esc_html(__('Einstellungen &rsaquo; FAU-WebSSO', self::textdomain)); ?></h2>
            <?php if($this->simplesaml_autoload_error): ?>
            <div class="error">
                <p><?php _e($this->simplesaml_autoload_error, self::textdomain); ?></p>
            </div>
            <?php endif; ?>
            <form method="post" action="options.php">
            <?php
            do_settings_sections(self::option_group);
            settings_fields(self::option_group);
            submit_button();
            ?>
            </form>
        </div>
        <?php
    }

    public function admin_init() {
        if (!is_multisite()) {
            register_setting(self::option_group, self::option_name, array($this, 'options_validate'));
        }
        
        add_settings_section('websso_options_section', false, array($this, 'websso_settings_section'), self::option_group);
        add_settings_field('force_websso', __('Zum SSO zwingen', self::textdomain), array($this, 'force_websso_field'), self::option_group, 'websso_options_section');

        add_settings_section('simplesaml_options_section', false, array($this, 'simplesaml_settings_section'), self::option_group);
        add_settings_field('simplesaml_include', __('Autoload-Pfad', self::textdomain), array($this, 'simplesaml_include_field'), self::option_group, 'simplesaml_options_section');
        add_settings_field('simplesaml_auth_source', __('Authentifizierungsquelle', self::textdomain), array($this, 'simplesaml_auth_source_field'), self::option_group, 'simplesaml_options_section');
    }

    public function websso_settings_section() {
        echo '<h3 class="title">' . __('Single Sign-On', self::textdomain) . '</h3>';
        echo '<p>' . __('Allgemeine SSO-Einstellungen.', self::textdomain) . '</p>';
    }

    public function force_websso_field() {
        $options = $this->get_options();
        echo '<input type="checkbox" id="force_websso" ', checked($options['force_websso'], true), ' name="' . self::option_name . '[force_websso]">';
    }

    public function simplesaml_settings_section() {
        echo '<h3 class="title">' . __('SimpleSAMLphp', self::textdomain) . '</h3>';
        echo '<p>' . __('Einstellungen des Service Provider.', self::textdomain) . '</p>';
    }

    public function simplesaml_include_field() {
        $options = $this->get_options();
        echo '<input type="text" id="simplesaml_include" class="regular-text ltr" name="' . self::option_name . '[simplesaml_include]" value="' . esc_attr($options['simplesaml_include']) . '">';
        echo '<p class="description">' . __('Relative Pfad ausgehend vom wp-content-Verzeichnis.', self::textdomain) . '</p>';
    }

    public function simplesaml_auth_source_field() {
        $options = $this->get_options();
        echo '<input type="text" id="simplesaml_auth_source" class="regular-text ltr" name="' . self::option_name . '[simplesaml_auth_source]" value="' . esc_attr($options['simplesaml_auth_source']) . '">';
    }

    public function options_validate($input) {
        $options = $this->get_options();

        $input['force_websso'] = !empty($input['force_websso']) ? true : false;

        $input['simplesaml_include'] = !empty($input['simplesaml_include']) ? esc_attr($input['simplesaml_include']) : $options['simplesaml_include'];

        $input['simplesaml_auth_source'] = isset($input['simplesaml_auth_source']) ? esc_attr($input['simplesaml_auth_source']) : $options['simplesaml_auth_source'];

        return $input;
    }

    public function login_enqueue_scripts() {
        wp_enqueue_style('websso', plugins_url('/', __FILE__) . 'css/websso-login.css', false, self::version, 'all');
    }
    
    public function login_form() {
        $login_url = add_query_arg('action', 'websso', home_url('/wp-login.php'));
        echo '<div class="message websso-login">';
        echo '<p>' . __('Sie haben Ihr IdM-Benutzerkonto bereits aktiviert?', self::textdomain) . '</p>';
        printf('<p>' . __('Bitte melden Sie sich mittels des folgenden Links an den %s-Webauftritt an.', self::textdomain) . '</p>', get_bloginfo('name'));
        printf('<p><a href="%1$s">' . __('Anmelden an den %2$s-Webauftritt', self::textdomain) . '</a></p>', $login_url, get_bloginfo('name'));
        echo '</div>';
    }

    public function users_attributes($columns) {
        $columns['attributes'] = __('Attribute', self::textdomain);
        return $columns;
    }

    public function users_attributes_columns($value, $column_name, $user_id) {

        if ('attributes' != $column_name) {
            return $value;
        }
        
        $attributes = array();

        $edu_person_affiliation = get_user_meta($user_id, 'edu_person_affiliation', true);
        if ($edu_person_affiliation) {
            $attributes[] = $edu_person_affiliation;
        }
        
        $edu_person_entitlement = get_user_meta($user_id, 'edu_person_entitlement', true);
        if ($edu_person_entitlement) {
            $attributes[] = $edu_person_entitlement;
        }
        
        return implode(', ', $attributes);
    }

    public function wpmu_new_user() {
        
    }
    
    private function create_user() {
        global $wp_roles;
        $user = new stdClass;

        if (isset($_POST['user_login'])) {
            $user->user_login = sanitize_user($_POST['user_login'], true);
        }

        if (isset($_POST['role']) && current_user_can('edit_users')) {
            $new_role = sanitize_text_field( $_POST['role'] );
            $potential_role = isset($wp_roles->role_objects[$new_role]) ? $wp_roles->role_objects[$new_role] : false;
            
            if ((is_multisite() && current_user_can('manage_sites')) || ($potential_role && $potential_role->has_cap('edit_users'))) {
                $user->role = $new_role;
            }

            $editable_roles = get_editable_roles();
            if (!empty($new_role) && empty($editable_roles[$new_role])) {
                wp_die(__('Sie können keinem Benutzer diese Rolle geben.', self::textdomain));
            }
        }

        if (isset($_POST['email'])) {
            $user->user_email = sanitize_text_field(wp_unslash($_POST['email']));
        }
        
        foreach (wp_get_user_contact_methods($user) as $method => $name) {
            if (isset($_POST[$method])) {
                $user->$method = sanitize_text_field($_POST[$method]);
            }
        }

        $user->comment_shortcuts = '';

        $user->use_ssl = 0;
        if (!empty($_POST['use_ssl'])) {
            $user->use_ssl = 1;
        }

        $errors = new WP_Error();

        if ($user->user_login == '') {
            $errors->add( 'user_login', __('<strong>Fehler:</strong> Bitte geben Sie einen Benutzernamen ein.', self::textdomain));
        }

        if (isset( $_POST['user_login']) && !validate_username($_POST['user_login'])) {
            $errors->add('user_login', __('<strong>Fehler:</strong> Dieser Benutzername kann nicht verwendet werden, da er ungültige Zeichen enthält. Bitte geben Sie einen gültigen Benutzernamen an.', self::textdomain));
        }
        
        if (username_exists($user->user_login)) {
            $errors->add('user_login', __('<strong>Fehler:</strong>: Dieser Benutzername ist bereits registriert. Bitte wähle Sie einen anderen.', self::textdomain));
        }

        if (empty($user->user_email)) {
            $errors->add('empty_email', __('<strong>Fehler:</strong> Bitte eine E-Mail-Adresse eingeben.', self::textdomain), array('form-field' => 'email'));
        }
        
        elseif (!is_email($user->user_email)) {
            $errors->add('invalid_email', __('<strong>Fehler:</strong>: Die E-Mail-Adresse ist ungültig.', self::textdomain), array('form-field' => 'email'));
        }
        
        elseif (email_exists($user->user_email)) {
            $errors->add('email_exists', __('<strong>Fehler:</strong>: Diese E-Mail-Adresse wurde bereits registriert, bitte wählen Sie eine andere.', self::textdomain), array( 'form-field' => 'email'));
        }

        if ($errors->get_error_codes()) {
            return $errors;
        }

        $user_id = wp_insert_user($user);

        return $user_id;
    }
    
    private function add_existing_user_notification($user_id) {
        $user = get_userdata($user_id);

        $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

        $roles = get_editable_roles();
        $role = $roles[ $_REQUEST['role'] ];

        $message = __('Hallo,%5$s%5$sSie wurden eingeladen, %1$s (%2$s) als %3$s beizutreten.%5$sBitte melden Sie sich mittels des folgenden Links an die Webseite %1$s an:%5$s %4$s', self::textdomain);
        $message = sprintf( $message, $blogname, home_url(), wp_specialchars_decode(translate_user_role($role['name'])), wp_login_url(), PHP_EOL);

        wp_mail($user->user_email, sprintf(__('[%s] Sie wurden eingeladen', self::textdomain), $blogname), $message);       
    }
    
    private function new_user_notification($user_id) {
        $user = get_userdata($user_id);

        $blogname = wp_specialchars_decode(get_option('blogname'), ENT_QUOTES);

        $message = __('Hallo,%4$s%4$sIhr Benutzerkonto %1$s wurde angelegt.%4$sBitte melden Sie sich mittels des folgenden Links an die Webseite %2$s an:%4$s%3$s%4$s%4$sViel Spaß!%4$s%4$s--Das Team von %2$s', self::textdomain);       
        $message = sprintf($message, $user->user_login, $blogname, wp_login_url(), PHP_EOL);
        
        wp_mail($user->user_email, sprintf(__('[%s] Ihr Benutzerkonto', self::textdomain), $blogname), $message);
    }
    
}
        
