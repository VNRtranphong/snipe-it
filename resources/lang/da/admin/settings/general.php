<?php

return [
    'ad'				        => 'Active Directory',
    'ad_domain'				    => 'Active Directory domæne',
    'ad_domain_help'			=> 'Dette er nogle gange det samme som dit e-mail-domæne, men ikke altid.',
    'ad_append_domain_label'    => 'Tilføj domænenavn',
    'ad_append_domain'          => 'Tilføj domænenavn til feltet brugernavn',
    'ad_append_domain_help'     => 'Brugeren er ikke forpligtet til at skrive "username@domain.local", de kan bare skrive "brugernavn".',
    'admin_cc_email'            => 'CC email',
    'admin_cc_email_help'       => 'Hvis du vil sende en kopi af checkin/checkout emails som er sendt til brugere til en ekstra email konto, så tilføj den her. Ellers efterlad feltet tomt.',
    'is_ad'				        => 'Dette er en Active Directory-server',
    'alerts'                	=> 'Alerts',
    'alert_title'               => 'Update Alert Settings',
    'alert_email'				=> 'Send advarsler til',
    'alert_email_help'    => 'Email addresses or distribution lists you want alerts to be sent to, comma separated',
    'alerts_enabled'			=> 'Advarsler aktiveret',
    'alert_interval'			=> 'Udløbsvarsel Threshold (i dage)',
    'alert_inv_threshold'		=> 'Lagervarsletærskel',
    'allow_user_skin'           => 'Tillad bruger skin',
    'allow_user_skin_help_text' => 'Afkrydsning her giver brugeren afgang til at overskrive UI skin med et andet skin.',
    'asset_ids'					=> 'Aktiv-id\'er',
    'audit_interval'            => 'Revisionsinterval',
    'audit_interval_help'       => 'If you are required to regularly physically audit your assets, enter the interval in months that you use. If you update this value, all of the "next audit dates" for assets with an upcoming audit date.',
    'audit_warning_days'        => 'Audit Warning Threshold',
    'audit_warning_days_help'   => 'Hvor mange dage i forvejen skal vi advare dig, når aktiver skal betales for revision?',
    'auto_increment_assets'		=> 'Generate auto-incrementing asset tags',
    'auto_increment_prefix'		=> 'Præfiks (valgfrit)',
    'auto_incrementing_help'    => 'Enable auto-incrementing asset tags first to set this',
    'backups'					=> 'Backups',
    'backups_help'              => 'Create, download, and restore backups ',
    'backups_restoring'         => 'Restoring from Backup',
    'backups_upload'            => 'Upload Backup',
    'backups_path'              => 'Backups on the server are stored in <code>:path</code>',
    'backups_restore_warning'   => 'Use the restore button <small><span class="btn btn-xs btn-warning"><i class="text-white fas fa-retweet" aria-hidden="true"></i></span></small> to restore from a previous backup. (This does not currently work with S3 file storage or Docker.<br><br>Your <strong>entire :app_name database and any uploaded files will be completely replaced</strong> by what\'s in the backup file.  ',
    'backups_logged_out'         => 'All existing users, including you, will be logged out once your restore is complete.',
    'backups_large'             => 'Very large backups may time out on the restore attempt and may still need to be run via command line. ',
    'barcode_settings'			=> 'Stregkodeindstillinger',
    'confirm_purge'			    => 'Bekræft rensning',
    'confirm_purge_help'		=> 'Indtast teksten "DELETE" i boksen nedenfor for at tømme dine slettede poster. Denne handling kan ikke fortrydes og vil slette alle soft-slettede elementer og brugere PERMANENT. (Du bør lave en sikkerhedskopi først, bare for at være sikker.)',
    'custom_css'				=> 'Brugerdefineret CSS',
    'custom_css_help'			=> 'Indtast eventuelle brugerdefinerede CSS overskridelser, du gerne vil bruge. Indsæt ikke de &lt;style&gt;&lt;/style&gt; tags.',
    'custom_forgot_pass_url'	=> 'Tilpasset Kodeord reset URL',
    'custom_forgot_pass_url_help'	=> 'Dette anvendes i stedet for den indbyggede URL til "glemt kodeord" på login billedet, anvendelig til at sende besøgende til en intern eller hosted LDAP kodeord reset funktion. Den vil effektivt afstille den lokale "glemt kodeord" funktionalitet.',
    'dashboard_message'			=> 'Dashboard meddelelse',
    'dashboard_message_help'	=> 'Denne tekst vil vises på dashboard for alle som har tilladelse til at se dashbordet.',
    'default_currency'  		=> 'Standardvaluta',
    'default_eula_text'			=> 'Standard EULA',
    'default_language'			=> 'Standard sprog',
    'default_eula_help_text'	=> 'Du kan også knytte brugerdefinerede EULA til specifikke aktivkategorier.',
    'display_asset_name'        => 'Vis aktivnavn',
    'display_checkout_date'     => 'Vis checkout dato',
    'display_eol'               => 'Vis EOL i tabelvisning',
    'display_qr'                => 'Vis firkantede koder',
    'display_alt_barcode'		=> 'Vis 1D stregkode',
    'email_logo'                => 'Email Logo',
    'barcode_type'				=> '2D stregkode type',
    'alt_barcode_type'			=> '1D stregkode type',
    'email_logo_size'       => 'Kvadratiske logoer i e-mail ser bedst ud. ',
    'enabled'                   => 'Enabled',
    'eula_settings'				=> 'EULA-indstillinger',
    'eula_markdown'				=> 'Denne EULA tillader <a href="https://help.github.com/articles/github-flavored-markdown/">Github smagsmærket markdown</a>.',
    'favicon'                   => 'Favicon',
    'favicon_format'            => 'Accepterede filtyper er ico, png, og gif. Andre billedformater fungerer muligvis ikke i alle browsere.',
    'favicon_size'          => 'Favicons skal være firkantede billeder, 16x16 pixels.',
    'footer_text'               => 'Ekstra footer tekst ',
    'footer_text_help'          => 'Denne tekst vil vises i footeren i højre side. Der kan anvendes links ved hjælp af <a href="https://help.github.com/articles/github-flavored-markdown/">Github flavored markdown</a>. Linjeskift, headere, billeder etc. kan føre til uforudsigelige resultater.',
    'general_settings'			=> 'Generelle indstillinger',
    'general_settings_keywords' => 'company support, signature, acceptance, email format, username format, images, per page, thumbnail, eula,  tos, dashboard, privacy',
    'general_settings_help'     => 'Default EULA and more',
    'generate_backup'			=> 'Generer sikkerhedskopiering',
    'header_color'              => 'Hovedfarge',
    'info'                      => 'Disse indstillinger giver dig mulighed for at tilpasse visse aspekter af din installation.',
    'label_logo'                => 'Etiketlogo',
    'label_logo_size'           => 'Firkantede logoer ser bedst ud - vil blive vist øverst til højre på hver aktiv etiket. ',
    'laravel'                   => 'Laravel Version',
    'ldap'                      => 'LDAP',
    'ldap_help'                 => 'LDAP/Active Directory',
    'ldap_client_tls_key'       => 'LDAP Client TLS Key',
    'ldap_client_tls_cert'      => 'LDAP Client-Side TLS Certificate',
    'ldap_enabled'              => 'LDAP aktiveret',
    'ldap_integration'          => 'LDAP Integration',
    'ldap_settings'             => 'LDAP-indstillinger',
    'ldap_client_tls_cert_help' => 'Client-Side TLS Certificate and Key for LDAP connections are usually only useful in Google Workspace configurations with "Secure LDAP." Both are required.',
     'ldap_client_tls_key'       => 'LDAP Client-Side TLS key',
    'ldap_login_test_help'      => 'Indtast validt LDAP brugernavn og kodeord fra den basis DN du angav ovenfor for at teste om dit LDAP login er korrekt konfigureret. DU SKAL FØRST OPDATERE og GEMME DINE LDAP INDSTILLINGER.',
    'ldap_login_sync_help'      => 'Dette tester kun om LDAP kan synkronisere korrekt. Hvis din LDAP authentisering ikke er korrekt, er det usikkert om brugere kan logge ind. DU SKAL FØRST OPDATERE OG GEMME DINE LDAP INDSTILLINGER.',
    'ldap_manager'              => 'LDAP Manager',
    'ldap_server'               => 'LDAP-server',
    'ldap_server_help'          => 'Dette skal starte med ldap: // (for ukrypteret eller TLS) eller ldaps: // (for SSL)',
    'ldap_server_cert'			=> 'Validering af LDAP SSL-certifikat',
    'ldap_server_cert_ignore'	=> 'Tillad ugyldigt SSL-certifikat',
    'ldap_server_cert_help'		=> 'Marker dette afkrydsningsfelt, hvis du bruger et selvtegnet SSL cert og vil gerne acceptere et ugyldigt SSL-certifikat.',
    'ldap_tls'                  => 'Brug TLS',
    'ldap_tls_help'             => 'Dette bør kun kontrolleres, hvis du kører STARTTLS på din LDAP-server.',
    'ldap_uname'                => 'LDAP Bind Brugernavn',
    'ldap_dept'                 => 'LDAP Afdeling',
    'ldap_phone'                => 'LDAP-telefonnummer',
    'ldap_jobtitle'             => 'LDAP Jobtitel',
    'ldap_country'              => 'LDAP Land',
    'ldap_pword'                => 'LDAP-bindingsadgangskode',
    'ldap_basedn'               => 'Base Bind DN',
    'ldap_filter'               => 'LDAP-filter',
    'ldap_pw_sync'              => 'LDAP Password Sync',
    'ldap_pw_sync_help'         => 'Fjern markeringen i dette felt, hvis du ikke vil beholde LDAP-adgangskoder synkroniseret med lokale adgangskoder. Deaktivering dette betyder, at dine brugere muligvis ikke kan logge ind, hvis din LDAP-server ikke er tilgængelig af en eller anden grund.',
    'ldap_username_field'       => 'Brugernavn felt',
    'ldap_lname_field'          => 'Efternavn',
    'ldap_fname_field'          => 'LDAP fornavn',
    'ldap_auth_filter_query'    => 'LDAP-godkendelse forespørgsel',
    'ldap_version'              => 'LDAP Version',
    'ldap_active_flag'          => 'LDAP Active Flag',
    'ldap_activated_flag_help'  => 'This value is used to determine whether a synced user can login to Snipe-IT. <strong>It does not affect the ability to check items in or out to them</strong>, and should be the <strong>attribute name</strong> within your AD/LDAP, <strong>not the value</strong>. <br><br>If this field is set to a field name that does not exist in your AD/LDAP, or the value in the AD/LDAP field is set to <code>0</code> or <code>false</code>, <strong>user login will be disabled</strong>. If the value in the AD/LDAP field is set to <code>1</code> or <code>true</code> or <em>any other text</em> means the user can log in. When the field is blank in your AD, we respect the <code>userAccountControl</code> attribute, which usually allows non-suspended users to log in.',
    'ldap_emp_num'              => 'LDAP medarbejdernummer',
    'ldap_email'                => 'LDAP Email',
    'ldap_test'                 => 'Test LDAP',
    'ldap_test_sync'            => 'Test LDAP Synchronization',
    'license'                   => 'Software licens',
    'load_remote_text'          => 'Fjernskrifter',
    'load_remote_help_text'		=> 'Denne Snipe-IT-installation kan indlæse scripts fra omverdenen.',
    'login'                     => 'Login Attempts',
    'login_attempt'             => 'Login Attempt',
    'login_ip'                  => 'IP Address',
    'login_success'             => 'Success?',
    'login_user_agent'          => 'User Agent',
    'login_help'                => 'List of attempted logins',
    'login_note'                => 'Login Note',
    'login_note_help'           => 'Indsæt eventuelt nogle sætninger på din loginskærm, for eksempel for at hjælpe personer, der har fundet en tabt eller stjålet enhed. Dette felt accepterer <a href="https://help.github.com/articles/github-flavored-markdown/">Github flavored markdown</a>',
    'login_remote_user_text'    => 'Loginoptions for fjernbrugere',
    'login_remote_user_enabled_text' => 'Tillad login med fjernbrugerheader',
    'login_remote_user_enabled_help' => 'Denne option tillader authentisering via REMOTE_USER headr jf. "Common Gateway Interface (rfc3875)"',
    'login_common_disabled_text' => 'Deaktiver andre authentiseringsmekanismer',
    'login_common_disabled_help' => 'Denne option deaktiverer andre authentiseringsmekanismer. Aktiver denne option, hvis du er sikker på at dit REMOTE_USER login allerede virker',
    'login_remote_user_custom_logout_url_text' => 'Tilpasset logout URL',
    'login_remote_user_custom_logout_url_help' => 'Hvis en URL er angivet her, vil brugere blive omstillet til den efter de har logget ud af Snipe-IT. Det er anvendeligt for at lukke bruger sessions i din authentiseringsmekanisme korrekt.',
    'login_remote_user_header_name_text' => 'Brugerdefineret brugernavn header',
    'login_remote_user_header_name_help' => 'Brug den angivne overskrift i stedet for REMOTE_USER',
    'logo'                    	=> 'Logo',
    'logo_print_assets'         => 'Brug i udskrift',
    'logo_print_assets_help'    => 'Brug branding på udskrevne aktivlister ',
    'full_multiple_companies_support_help_text' => 'Begrænsning af brugere (herunder admins) tildelt virksomheder til deres virksomheds aktiver.',
    'full_multiple_companies_support_text' => 'Fuld flere virksomheder support',
    'show_in_model_list'   => 'Vis i modeldropdown',
    'optional'					=> 'valgfri',
    'per_page'                  => 'Resultater pr. Side',
    'php'                       => 'PHP Version',
    'php_info'                  => 'PHP Info',
    'php_overview'              => 'PHP',
    'php_overview_keywords'     => 'phpinfo, system, info',
    'php_overview_help'         => 'PHP System info',
    'php_gd_info'               => 'Du skal installere php-gd for at vise QR-koder, se installationsvejledningen.',
    'php_gd_warning'            => 'PHP Image Processing og GD plugin er IKKE installeret.',
    'pwd_secure_complexity'     => 'Password Complexity',
    'pwd_secure_complexity_help' => 'Vælg, hvilke regler for adgangskompleksitet du ønsker at håndhæve.',
    'pwd_secure_complexity_disallow_same_pwd_as_user_fields' => 'Password cannot be the same as first name, last name, email, or username',
    'pwd_secure_complexity_letters' => 'Require at least one letter',
    'pwd_secure_complexity_numbers' => 'Require at least one number',
    'pwd_secure_complexity_symbols' => 'Require at least one symbol',
    'pwd_secure_complexity_case_diff' => 'Require at least one uppercase and one lowercase',
    'pwd_secure_min'            => 'Minimumskode til adgangskode',
    'pwd_secure_min_help'       => 'Mindste tilladte værdi er 8',
    'pwd_secure_uncommon'       => 'Forhindre almindelige adgangskoder',
    'pwd_secure_uncommon_help'  => 'Dette vil gøre det muligt for brugere at bruge almindelige adgangskoder fra de 10.000 passwords, der er rapporteret i tilfælde af brud.',
    'qr_help'                   => 'Aktiver QR-koder først for at indstille dette',
    'qr_text'                   => 'QR Kode Tekst',
    'saml'                      => 'SAML',
    'saml_title'                => 'Update SAML settings',
    'saml_help'                 => 'SAML settings',
    'saml_enabled'              => 'SAML aktiveret',
    'saml_integration'          => 'SAML-integration',
    'saml_sp_entityid'          => 'Enheds ID',
    'saml_sp_acs_url'           => 'Assertion Consumer Service (ACS) URL',
    'saml_sp_sls_url'           => 'Single Logout Service (SLS) URL',
    'saml_sp_x509cert'          => 'Offentligt Certifikat',
    'saml_sp_metadata_url'      => 'Metadata URL',
    'saml_idp_metadata'         => 'SAML IdP Metadata',
    'saml_idp_metadata_help'    => 'Du kan angive IdP metadata ved hjælp af en URL eller XML-fil.',
    'saml_attr_mapping_username' => 'Attribute Mapping - Brugernavn',
    'saml_attr_mapping_username_help' => 'NavnID vil blive brugt hvis attributmapping er uspecificeret eller ugyldig.',
    'saml_forcelogin_label'     => 'SAML Force Login',
    'saml_forcelogin'           => 'Gør SAML til det primære login',
    'saml_forcelogin_help'      => 'Du kan bruge \'/login?nosaml\' for at komme til den normale loginside.',
    'saml_slo_label'            => 'SAML Single log af',
    'saml_slo'                  => 'Send en LogoutRequest til IdP ved Log af',
    'saml_slo_help'             => 'Dette vil omdirigere brugeren til IdP ved logout. Lad være umarkeret hvis IdP ikke korrekt understøtter SP-initieret SAML SLO.',
    'saml_custom_settings'      => 'SAML Custom Settings',
    'saml_custom_settings_help' => 'Du kan angive yderligere indstillinger til onelogin/php-saml biblioteket. Brug på egen risiko.',
    'saml_download'             => 'Download Metadata',
    'setting'                   => 'Indstilling',
    'settings'                  => 'Indstillinger',
    'show_alerts_in_menu'       => 'Vis meddelelser i top menu',
    'show_archived_in_list'     => 'Arkiverede aktiver',
    'show_archived_in_list_text'     => 'Vis arkiverede aktiver i "Alle aktiver" listen',
    'show_assigned_assets'      => 'Vis assets tildelt til assets',
    'show_assigned_assets_help' => 'Vis assets som blev tildelt til andre assets i Vis bruger -> Aktiver, Vis bruger -> Info -> Udskriv alle Tildelt og på konto -> Vis Tildelte aktiver.',
    'show_images_in_email'     => 'Vis billeder i emails',
    'show_images_in_email_help'   => 'Afkryds denne boks hvis din Snipe-IT installation er bag en VPN eller i et lukket netværk og brugere udenfor netværket vil forhinderes i at anvende billeder fra netværket i deres emails.',
    'site_name'                 => 'Side navn',
    'slack'                     => 'Slack',
    'slack_title'               => 'Update Slack Settings',
    'slack_help'                => 'Slack settings',
    'slack_botname'             => 'Slack Botname',
    'slack_channel'             => 'Slack Channel',
    'slack_endpoint'            => 'Slap endepunkt',
    'slack_integration'         => 'Slack Settings',
    'slack_integration_help'    => 'Slack integration er valgfri, men endpoint og kanal er påkrævet, hvis du ønsker at bruge det. For at konfigurere Slack integration, skal du først <a href=":slack_link" target="_new" rel="noopener">oprette en indgående webhook</a> på din Slack konto. Klik på knappen <strong>Test Slack Integration</strong> for at bekræfte, at dine indstillinger er korrekte, før du gemmer. ',
    'slack_integration_help_button'    => 'Nå du har gemt din Slack information, vil testknappen blive synlig.',
    'slack_test_help'           => 'Test om din Slack integration er konfigureret korrekt. DU SKAL FØRST OPDATERE OG GEMME DINE SLACK\'s INDSTILLINGER.',
    'snipe_version'  			=> 'Snipe-IT version',
    'support_footer'            => 'Understøt footer links ',
    'support_footer_help'       => 'Angiv hvem der kan se links i Snipe-IT Support info og brugermanual',
    'version_footer'            => 'Version in footer ',
    'version_footer_help'       => 'Angiv hvem der kan se Snipe-IT versions- og buildnummer.',
    'system'                    => 'Systemoplysninger',
    'update'                    => 'Opdater indstillinger',
    'value'                     => 'Værdi',
    'brand'                     => 'Branding',
    'brand_keywords'            => 'footer, logo, print, theme, skin, header, colors, color, css',
    'brand_help'                => 'Logo, Site Name',
    'web_brand'                 => 'Web Branding Type',
    'about_settings_title'      => 'Om indstillinger',
    'about_settings_text'       => 'Disse indstillinger giver dig mulighed for at tilpasse visse aspekter af din installation.',
    'labels_per_page'           => 'Etiketter pr. Side',
    'label_dimensions'          => 'Etiket dimensioner (inches)',
    'next_auto_tag_base'        => 'Næste automatisk stigning',
    'page_padding'              => 'Sidemarginer (tommer)',
    'privacy_policy_link'       => 'Link til persondatapolitik',
    'privacy_policy'            => 'Persondatapolitik',
    'privacy_policy_link_help'  => 'Hvis der inkluderes en URL her, vil der blive inkluderet et link til din persondatapolitik i app\'ens footer og i alle emails systemet sender ud ( overensstemmelse med GDPR). ',
    'purge'                     => 'Ryd slettet poster',
    'purge_deleted'             => 'Purge Deleted ',
    'labels_display_bgutter'    => 'Etiket bundgitter',
    'labels_display_sgutter'    => 'Label side rende',
    'labels_fontsize'           => 'Etiket skriftstørrelse',
    'labels_pagewidth'          => 'Labelark bredde',
    'labels_pageheight'         => 'Etiketark højde',
    'label_gutters'        => 'Etiketafstand (tommer)',
    'page_dimensions'        => 'Side dimensioner (tommer)',
    'label_fields'          => 'Label synlige felter',
    'inches'        => 'inches',
    'width_w'        => 'w',
    'height_h'        => 'h',
    'show_url_in_emails'                => 'Link til Snipe-IT i e-mails',
    'show_url_in_emails_help_text'      => 'Fjern markeringen i dette felt, hvis du ikke vil linke tilbage til din Snipe-IT-installation i dine e-mail-fodbold. Nyttigt, hvis de fleste af dine brugere aldrig logger ind.',
    'text_pt'        => 'pt',
    'thumbnail_max_h'   => 'Max miniaturehøjde',
    'thumbnail_max_h_help'   => 'Maksimal højde i pixels, som miniaturer kan vises i listevisningen. Min 25, maks 500.',
    'two_factor'        => 'To faktor godkendelse',
    'two_factor_secret'        => 'Tofaktorkode',
    'two_factor_enrollment'        => 'Two-Factor Enrollment',
    'two_factor_enabled_text'        => 'Aktivér to faktorer',
    'two_factor_reset'        => 'Reset 2-Factor Secret',
    'two_factor_reset_help'        => 'Dette vil tvinge brugeren til at tilmelde deres enhed med Google Authenticator igen. Dette kan være nyttigt, hvis deres tilmeldte enhed er tabt eller stjålet.',
    'two_factor_reset_success'          => 'To faktor enhed nulstilles',
    'two_factor_reset_error'          => 'To-faktor enhed reset mislykkedes',
    'two_factor_enabled_warning'        => 'Aktivering af to-faktor, hvis den ikke er aktiveret, vil straks tvinge dig til at godkende med en Google Auth-indskrevet enhed. Du vil have mulighed for at tilmelde din enhed, hvis en ikke er indskrevet på nuværende tidspunkt.',
    'two_factor_enabled_help'        => 'Dette aktiverer tofaktors godkendelse ved hjælp af Google Authenticator.',
    'two_factor_optional'        => 'Selektiv (Brugere kan aktivere eller deaktivere hvis tilladt)',
    'two_factor_required'        => 'Påkrævet for alle brugere',
    'two_factor_disabled'        => 'handicappet',
    'two_factor_enter_code'	=> 'Indtast tofaktorkode',
    'two_factor_config_complete'	=> 'Indsend kode',
    'two_factor_enabled_edit_not_allowed' => 'Din administrator tillader ikke dig at redigere denne indstilling.',
    'two_factor_enrollment_text'	=> "To faktor godkendelse er påkrævet, men din enhed er endnu ikke blevet tilmeldt. Åbn din Google Authenticator-app og scan QR-koden nedenfor for at tilmelde din enhed. Når du har tilmeldt din enhed, skal du indtaste koden nedenfor",
    'require_accept_signature'      => 'Kræver Signatur',
    'require_accept_signature_help_text'      => 'Aktivering af denne funktion kræver, at brugerne fysisk logger af ved at acceptere et aktiv.',
    'left'        => 'venstre',
    'right'        => 'højre',
    'top'        => 'top',
    'bottom'        => 'bund',
    'vertical'        => 'lodret',
    'horizontal'        => 'vandret',
    'unique_serial'                => 'Unikke serienumre',
    'unique_serial_help_text'                => 'Markering af denne boks medfører en unik begrænsning af aktivserier',
    'zerofill_count'        => 'Længde af aktivetiketter, herunder zerofill',
    'username_format_help'   => 'Denne indstilling vil kun blive brugt af importprocessen, hvis et brugernavn ikke er angivet, og vi er nødt til at generere et brugernavn til dig.',
    'oauth_title' => 'OAuth API Settings',
    'oauth' => 'OAuth',
    'oauth_help' => 'Oauth Endpoint Settings',
    'asset_tag_title' => 'Update Asset Tag Settings',
    'barcode_title' => 'Update Barcode Settings',
    'barcodes' => 'Barcodes',
    'barcodes_help_overview' => 'Barcode &amp; QR settings',
    'barcodes_help' => 'This will attempt to delete cached barcodes. This would typically only be used if your barcode settings have changed, or if your Snipe-IT URL has changed. Barcodes will be re-generated when accessed next.',
    'barcodes_spinner' => 'Attempting to delete files...',
    'barcode_delete_cache' => 'Delete Barcode Cache',
    'branding_title' => 'Update Branding Settings',
    'general_title' => 'Update General Settings',
    'mail_test' => 'Send Test',
    'mail_test_help' => 'This will attempt to send a test mail to :replyto.',
    'filter_by_keyword' => 'Filter by setting keyword',
    'security' => 'Security',
    'security_title' => 'Update Security Settings',
    'security_keywords' => 'password, passwords, requirements, two factor, two-factor, common passwords, remote login, logout, authentication',
    'security_help' => 'Two-factor, Password Restrictions',
    'groups_keywords' => 'permissions, permission groups, authorization',
    'groups_help' => 'Account permission groups',
    'localization' => 'Localization',
    'localization_title' => 'Update Localization Settings',
    'localization_keywords' => 'localization, currency, local, locale, time zone, timezone, international, internatinalization, language, languages, translation',
    'localization_help' => 'Language, date display',
    'notifications' => 'Notifications',
    'notifications_help' => 'Email alerts, audit settings',
    'asset_tags_help' => 'Incrementing and prefixes',
    'labels' => 'Labels',
    'labels_title' => 'Update Label Settings',
    'labels_help' => 'Label sizes &amp; settings',
    'purge' => 'Purge',
    'purge_keywords' => 'permanently delete',
    'purge_help' => 'Purge Deleted Records',
    'ldap_extension_warning' => 'It does not look like the LDAP extension is installed or enabled on this server. You can still save your settings, but you will need to enable the LDAP extension for PHP before LDAP syncing or login will work.',
    'ldap_ad' => 'LDAP/AD',
    'employee_number' => 'Employee Number',
    'create_admin_user' => 'Create a User ::',
    'create_admin_success' => 'Success! Your admin user has been added!',
    'create_admin_redirect' => 'Click here to go to your app login!',
    'setup_migrations' => 'Database Migrations ::',
    'setup_no_migrations' => 'There was nothing to migrate. Your database tables were already set up!',
    'setup_successful_migrations' => 'Your database tables have been created',
    'setup_migration_output' => 'Migration output:',
    'setup_migration_create_user' => 'Next: Create User',
    'ldap_settings_link' => 'LDAP Settings Page',
    'slack_test' => 'Test <i class="fab fa-slack"></i> Integration',
];
