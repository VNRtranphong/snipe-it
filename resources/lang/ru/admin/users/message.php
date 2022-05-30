<?php

return array(

    'accepted'                  => 'Вы успешно приняли актив.',
    'declined'                  => 'Вы успешно отклонили актив.',
    'bulk_manager_warn'	        => 'Ваши пользователи были успешно обновлены, однако запись менеджера не была сохранена, потому что выбранный менеджер также присутствовал в редактируемом списке пользователей, и пользователи не могут быть менеджерами самим себе. Пожалуйста выберите ваших пользователей снова, за исключением их менеджера.',
    'user_exists'               => 'Пользователь уже существует!',
    'user_not_found'            => 'Пользователь [:id] не существует.',
    'user_login_required'       => 'Поле "Логин" является обязательным',
    'user_password_required'    => 'Поле "Пароль" является обязательным.',
    'insufficient_permissions'  => 'Недостаточно прав.',
    'user_deleted_warning'      => 'Этот пользователь был удален. Вы должны его восстановить чтобы иметь возможность его редактировать или привязывать новые активы.',
    'ldap_not_configured'        => 'Интеграция с LDAP не настроена для этой инсталляции.',
    'password_resets_sent'      => 'Ссылка для сброса пароля была отправлена выбранным пользователям которые имеют действительный адрес электронной почты а активированы.',
    'password_reset_sent'       => 'Ссылка для сброса пароля была отправлена на адрес :email!',


    'success' => array(
        'create'    => 'Пользователь успешно создан.',
        'update'    => 'Пользователь успешно изменен.',
        'update_bulk'    => 'Пользователи успешно изменены!',
        'delete'    => 'Пользователь успешно удален.',
        'ban'       => 'Пользователь успешно заблокирован.',
        'unban'     => 'Пользователь успешно разблокирован.',
        'suspend'   => 'Пользователь успешно заморожен.',
        'unsuspend' => 'Пользователь успешно разморожен.',
        'restored'  => 'Пользователь успешно восстановлен.',
        'import'    => 'Пользователи успешно импортированы.',
    ),

    'error' => array(
        'create' => 'При создании пользователя возникла проблема. Пожалуйста попробуйте снова.',
        'update' => 'При изменении пользователя возникла проблема. Пожалуйста попробуйте снова.',
        'delete' => 'При удалении пользователя возникла проблема. Пожалуйста попробуйте снова.',
        'delete_has_assets' => 'У пользователя есть назначенные ему активы и не может быть удалён.',
        'unsuspend' => 'При разморозке пользователя возникла проблема. Пожалуйста попробуйте снова.',
        'import'    => 'При импорте пользователей произошла ошибка. Попробуйте еще раз.',
        'asset_already_accepted' => 'Этот актив уже был принят.',
        'accept_or_decline' => 'Примите или отклоните актив.',
        'incorrect_user_accepted' => 'Актив, который вы попытались принять, не был записан на вас.',
        'ldap_could_not_connect' => 'Не могу подключиться к серверу LDAP. Проверьте настройки LDAP сервера в файле конфигурации LDAP.<br>Ошибка от LDAP сервера:',
        'ldap_could_not_bind' => 'Не могу связаться (bind) с сервером LDAP. Проверьте настройки LDAP сервера в файле конфигурации LDAP.<br>Ошибка от LDAP сервера: ',
        'ldap_could_not_search' => 'Не могу найти сервер LDAP. Проверьте настройки LDAP сервера в файле конфигурации LDAP.<br>Ошибка от LDAP сервера:',
        'ldap_could_not_get_entries' => 'Не могу загрузить записи с сервера LDAP. Проверьте настройки LDAP сервера в файле конфигурации LDAP.<br>Ошибка от LDAP сервера:',
        'password_ldap' => 'Пароль для этой учетной записи управляется LDAP/Active Directory. Пожалуйста, свяжитесь с департаментом ИТ, чтобы изменить свой пароль. ',
    ),

    'deletefile' => array(
        'error'   => 'Не удалось удалить файл. Повторите попытку.',
        'success' => 'Файл успешно удален.',
    ),

    'upload' => array(
        'error'   => 'Не удалось загрузить файл(ы). Повторите попытку.',
        'success' => 'Файл(ы) успешно загружены.',
        'nofiles' => 'Не выбраны файлы для загрузки',
        'invalidfiles' => 'Один или несколько ваших файлов слишком большого размера или имеют неподдерживаемый формат. Разрешены только следующие форматы файлов: png, gif, jpg, doc, docx, pdf, txt.',
    ),

);
