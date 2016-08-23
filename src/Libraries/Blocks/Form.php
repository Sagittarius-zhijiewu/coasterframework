<?php namespace CoasterCms\Libraries\Blocks;

use CoasterCms\Exceptions\CmsPageException;
use CoasterCms\Helpers\Cms\BlockManager;
use CoasterCms\Libraries\Builder\FormMessage;
use CoasterCms\Libraries\Builder\PageBuilder;
use CoasterCms\Models\Block;
use CoasterCms\Models\BlockFormRule;
use CoasterCms\Models\FormSubmission;
use CoasterCms\Models\Page;
use CoasterCms\Models\Theme;
use CoasterCms\Models\ThemeBlock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Mail\Message;
use Mail;
use Request;
use Session;
use Validator;
use View;

class Form extends _Base
{
    public static $blocks_key = 'form';

    public static function display($block, $block_data, $options = array())
    {
        $form_data = new \stdClass;
        $template = !empty($options['view']) ? $options['view'] : $block->name;
        if (!empty($block_data)) {
            $form_data = unserialize($block_data);
            if (!empty($form_data->template)) {
                $template = $form_data->template;
            }
        } else {
            $form_data->captcha = false;
            $form_data->email_from = '';
            $form_data->email_to = '';
            $form_data->template = '';
            $form_data->page_to = '';
            $form_data->template = '';
        }
        $template = 'themes.' . PageBuilder::getData('theme') . '.blocks.forms.' . $template;
        if (View::exists($template)) {
            $forwarded_url = Request::input('forwarded_url');
            if (empty($forwarded_url)) {
                $options['url'] = Request::fullUrl() . '#form' . $block->id;
            } else {
                $options['url'] = $forwarded_url . '#form' . $block->id;
            }
            unset($options['version']);
            $options['files'] = !empty($options['files'])?$options['files']:true;
            $options['id'] = !empty($options['id'])?$options['id']:'form' . $block->id;
            $form_fields = View::make($template, array('form_data' => $form_data))->render();
            return View::make('coasterCms::form.wrap', array('block_id' => $block->id, 'page_id' => PageBuilder::pageId(), 'form_attrs' => $options, 'form_fields' => $form_fields));
        } else {
            return 'Form template not found';
        }
    }

    public static function edit($block, $block_data, $page_id = 0, $parent_repeater = null)
    {
        if (empty($block_data) || !($form_data = @unserialize($block_data))) {
            $form_data = new \stdClass;
            $form_data->email_from = '';
            $form_data->email_to = '';
            $form_data->template = 0;
            $form_data->page_to = '';
        } else {
            $form_data->template = $form_data->template == $block->name ? 0 : $form_data->template;
        }
        $form_data->captcha_hide = '';
        if (!isset($form_data->captcha)) {
            $form_data->captcha = false;
        }
        $form_data->pages_array = Page::get_page_list();
        $form_data->template_array = [0 => '-- Use view from template --'];
        $theme = Theme::find(config('coaster::frontend.theme'));
        if (!empty($theme)) {
            $forms = base_path('/resources/views/themes/' . $theme->theme . '/blocks/forms');
            if (is_dir($forms)) {
                foreach (scandir($forms) as $form) {
                    if (!is_dir($forms . DIRECTORY_SEPARATOR . $form)) {
                        $form_file = explode(".", $form);
                        if (!empty($form_file[0])) {
                            if (strpos(file_get_contents($forms . DIRECTORY_SEPARATOR . $form), 'captcha')) {
                                $captcha = " (supports captcha)";
                            } else {
                                $captcha = " (does not support captcha)";
                                if ($form_data->template == $form_file[0]) {
                                    $form_data->captcha_hide = 'hide';
                                }
                            }
                            $form_data->template_array[$form_file[0]] = $form_file[0] . $captcha;
                        }
                    }
                }
            }
        }
        self::$extra_data['page_id'] = $page_id;
        self::$edit_id = array($block->id);
        return $form_data;
    }

    public static function submit($page_id, $blocks_key, $repeater_info = null)
    {
        $updated_form_blocks = Request::input($blocks_key);
        if (!empty($updated_form_blocks)) {
            foreach ($updated_form_blocks as $block_id => $updated_form_data) {
                $form_data = new \stdClass;
                $form_data->email_from = $updated_form_data['from'];
                $form_data->email_to = $updated_form_data['to'];
                $form_data->template = !empty($updated_form_data['template'])?$updated_form_data['template']:0;
                $form_data->page_to = $updated_form_data['page'];
                $form_data->captcha = !empty($updated_form_data['captcha']) ? true : false;
                $block_content = serialize($form_data);
                BlockManager::update_block($block_id, $block_content, $page_id);
            }
        }
    }

    // form specific functions below

    /**
     * @param array $form_data
     * @return false|RedirectResponse
     * @throws CmsPageException
     */
    public static function submission($form_data)
    {
        $default_rules = array(
            'block_id' => 'required|integer',
            'page_id' => 'required|integer'
        );

        // check basic rules
        $v = Validator::make($form_data, $default_rules);
        if ($v->passes() && empty($form_data['email_check'])) {
            // load form settings
            $live_version = PageBuilder::pageLiveVersionId();
            $form_settings = BlockManager::get_block($form_data['block_id'], $form_data['page_id'], null, $live_version);
            if (empty($form_settings)) {
                // check if forms a global block
                $in_theme = ThemeBlock::where('theme_id', '=', config('coaster::frontend.theme'))->where('block_id', '=', $form_data['block_id'])->first();
                if (!empty($in_theme)) {
                    $form_settings = BlockManager::get_block($form_data['block_id']);
                }
            }
            if (!empty($form_settings)) {
                $form_settings = unserialize($form_settings);

                // load captcha
                $_POST['captcha_code'] = empty($_POST['captcha_code']) ? '' : $_POST['captcha_code'];
                if (include(public_path(config('coaster::admin.public').'/securimage/securimage.php'))) {
                    $secure_image = new \Securimage();
                }
                $captcha = isset($secure_image) ? $secure_image->check($_POST['captcha_code']) : false;

                $form_rules = BlockFormRule::get_rules($form_settings->template);
                $v = Validator::make($form_data, $form_rules);

                // check form rules
                if ($v->passes() && !($form_settings->captcha == true && !$captcha)) {
                    // delete blank and system fields
                    $block = Block::find($form_data['block_id']);
                    unset($form_data['block_id']);
                    unset($form_data['page_id']);
                    unset($form_data['captcha_code']);
                    unset($form_data['_token']);
                    unset($form_data['email_check']); //honeypot

                    $files = array();
                    foreach ($form_data as $field => $value) {
                        if (empty($value)) {
                            unset($form_data[$field]);
                        }
                        if (Request::hasFile($field)) {
                            $files[$field] = $value;
                            unset($form_data[$field]);
                        }
                    }

                    // save form submission
                    $form_submission = new FormSubmission;
                    $form_submission->form_block_id = $block->id;
                    $form_submission->content = serialize($form_data);
                    $form_submission->sent = 0;
                    $form_submission->from_page_id = PageBuilder::pageId();
                    $form_submission->save();

                    foreach ($files as $field => $value) {
                        if (Request::hasFile($field)) {
                            $upload_folder = '/uploads/system/forms/' . $block->id;
                            $full_upload_path = public_path() . $upload_folder;
                            if (!file_exists($full_upload_path)) {
                                mkdir($full_upload_path, 0755, true);
                            }
                            $unique_filename = $field . ' ' . $form_submission->id . ' ' . Request::file($field)->getClientOriginalName();
                            Request::file($field)->move($full_upload_path, $unique_filename);
                            $form_data[$field] = \HTML::link($upload_folder . '/' . $unique_filename, $unique_filename);
                        }
                    }

                    $form_submission->content = serialize($form_data);
                    $form_submission->save();

                    // get email details to send to
                    $email_details = array(
                        'to' => $form_settings->email_to,
                        'from' => $form_settings->email_from
                    );
                    if (empty($form_settings->email_to)) {
                        $email_details['to'] = config('coaster::site.email');
                    }
                    if (empty($form_settings->email_from)) {
                        $email_details['from'] = config('coaster::site.email');
                    }
                    $email_details['subject'] = config('coaster::site.name') . ' - ' . $block->label;

                    //write & send mail
                    $email_check = Validator::make($email_details, array('to' => 'required', 'from' => 'email|required'));
                    if ($email_check->passes()) {
                        if (strpos($email_details['to'], ',') !== false) {
                            $email_details['to'] = explode(',', $email_details['to']);
                        }

                        $email_details['reply'] = false;
                        $body = '';
                        foreach ($form_data as $field => $value) {
                            if (is_array($value)) {
                                $value = implode(", ", $value);
                            }
                            if (strpos($value, "\r\n") !== false) {
                                $value = "<br />" . str_replace("\r\n", "<br />", $value);
                            }
                            $body .= ucwords(str_replace('_', ' ', $field)) . ": $value <br />";
                            if (stristr($field, 'email') !== false) {
                                $email_details['reply'] = $value;
                            }
                        }

                        $emailsViews = ['themes.' . PageBuilder::getData('theme') . '.emails.'];
                        $emailsViews[1] = $emailsViews[0] . $form_settings->template . '.';

                        $sendTemplate = null;
                        $replyTemplate = null;
                        foreach ($emailsViews as $emailsView) {
                            if (!$sendTemplate && View::exists($emailsView . 'default')) {
                                $sendTemplate = $emailsView . 'default';
                            }
                            if (!$replyTemplate && View::exists($emailsView . 'reply')) {
                                $replyTemplate =  $emailsView . 'reply';
                            }
                        }
                        if (!$sendTemplate) {
                            throw new CmsPageException('No.default default email template', 500);
                        }
                        $replyTemplate = $replyTemplate ?: $sendTemplate;

                        Mail::send($sendTemplate, array('body' => $body, 'form_data' => $form_data), function (Message $message) use ($email_details) {
                            if ($email_details['reply']) {
                                $message->from($email_details['reply']);
                            } else {
                                $message->from($email_details['from']);
                            }
                            $message->to($email_details['to']);
                            $message->subject($email_details['subject']);
                        });

                        if ($email_details['reply']) {
                            Mail::send($replyTemplate, array('body' => $body, 'form_data' => $form_data), function (Message $message) use ($email_details) {
                                $message->to($email_details['reply']);
                                $message->from($email_details['reply']);
                                $message->subject($email_details['subject']);
                            });
                        }

                        $failures = Mail::failures();

                        if (empty($failures)) {
                            $form_submission->sent = 1;
                            $form_submission->save();
                        }
                    }
                    Session::set('form_data', $form_submission);
                    return \redirect(PageBuilder::pageUrl($form_settings->page_to));
                } else {
                    FormMessage::set($v->messages());
                    if (!$captcha) {
                        FormMessage::add('captcha_code', 'Invalid Captcha Code!');
                    }
                    return false;
                }

            }
        } elseif (!PageBuilder::getData('externalTemplate')) {
            // error if called from within CMS
            throw new CmsPageException('Form Not Found', 500);
        }

        return false;
    }

    public static function block_settings_action()
    {
        return ['action' => 'themes/forms', 'name' => 'Manage form input validation rules'];
    }

}
