<?php

/**
 * Plugin Central de Contatos - atalho para a configuração
 */

Session::checkLoginUser();
Html::redirect(PluginCentraldecontatosConfig::url('config.form.php'));
