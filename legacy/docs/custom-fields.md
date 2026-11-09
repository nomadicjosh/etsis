# Custom Fields

### Introduction

In keeping with the philosophy of [historical data](https://web.archive.org/web/20201019203935/https://www.edutracsis.com/education/why-choose-edutrac-erp-historical-data/) and [custom reports](https://web.archive.org/web/20201019203935/https://www.edutracsis.com/education/why-choose-edutrac-erp-custom-reports/), custom fields in eduTrac SIS is done a little differently from what is normally seen in other systems. It also takes a little more development time, but at least you will be able to run reports which include custom fields with no effort at all.

Since custom fields are handled differently in eduTrac SIS, there should be some clarification on what is meant by custom fields. Custom fields in eduTrac SIS means the addition of fields in a certain table. For example, if you would like to capture other types of data when an online application is submitted, you would need to alter the *application* table in order to capture the other fields that you need.

Note: Custom fields are only available for application, person, program, course, and course section.

### Before Getting Started

The best way of creating these custom fields is by doing it through a plugin. But before we get started, there are few resources you need to have readily at hand:

1. [Register Activation Hook](https://web.archive.org/web/20201019203935/http://developer.edutracsis.com/reference/classes/plugin/register_activation_hook/): This hook can be used to run code (such as database queries) when the plugin is activated.
2. [Register Deactivation Hook](https://web.archive.org/web/20201019203935/http://developer.edutracsis.com/reference/classes/plugin/register_deactivation_hook/): This hood can be used to run code (such as database queries) when the plugin is deactivated.
3. [Custom Field Action Hooks](https://web.archive.org/web/20201019203935/http://developer.edutracsis.com/?s=_form&post_type%5B%5D=wp-parser-hook): Here is a list of action hooks you can use for adding custom fields to a particular screen.
4. [Database Action Hooks](https://web.archive.org/web/20201019203935/http://developer.edutracsis.com/?s=_db_table&post_type%5B%5D=wp-parser-hook): Here is a list of database action hooks to use to data is submitted and/or updated.
5. [Plugin Boilerplate](https://web.archive.org/web/20201019203935/https://www.edutracsis.com/download/custom-field-bp-plugin/): Download and follow the example of this boilerplate plugin. It has everything you need in order to add custom fields.

### How To

The boilerplate plugin will be used as an example to help you better understand the code and be able to adapt it to your needs. The boilerplate plugin shows you how to show a custom field on the application screen. As a preliminary, you need to first understand the Custom Field class.

You will need to instantiate the custom field class like the example below:

```
/**
 * The default location for custom fields is dashboard.
 * If you want to show custom fields on the online
 * application via myeduTrac self service portal,
 * then you will need to add the location parameter
 * and set it like so: $field = _etsis_custom_field('myet').
 */
$field = _etsis_custom_field();
```

There are two methods in this class to be aware of: *custom\_field()* and *myet\_appl\_field()*. If you instantiate the class to use ‘myet’ to add fields to the online application, then it will use the *myet\_appl\_field()* method, otherwise it will use the default method *custom\_field()*.

Once the class is instantiated, now you can begin to add your fields. The method *add()* can be used to accomplish this:

```
/**
 * Adds field elements.
 * 
 * @since 6.1.10
 * @param string $field_type Custom field type (text, textarea, select, checkbox, radio).
 * @param string $db_column Custom database column or field name.
 * @param string $label Label tag.
 * @param string $default_value Value pulled from the database.
 * @param bool $required Determines if field is required.
 * @param array $options Key value pairs for select options, checkbox options, or radio options.
 */
$field->add($field_type, $db_column, $label, $default_value = '', $required = false, $options = '');
```

Now that the class is instantiated and the fields added, you will then need to end it with the *build()* method. This method brings it all together in order to show the custom field as well as any dynamic data.

Now that you understand the custom field class, now lets take a look at what’s in the boilerplate plugin. The first function is used to alter the *application* table in order to add our new custom field.

```
function add_appl_db_field()
{
    $app = \Liten\Liten::getInstance();
    $column = $app->db->query("SHOW COLUMNS FROM `application` LIKE 'exam'");
    $q = $column->find(function($data) {
        $array = [];
        foreach ($data as $d) {
            $array[] = $d;
        }
        return $array;
    });

    if (count($q) <= 0) {
        $sql = [];
        $sql[] = 'ALTER TABLE `application` ADD COLUMN `exam` varchar(5) NOT NULL AFTER `admitStatus`;';
        foreach ($sql as $query) {
            $app->db->query($query);
        }
    }
}
register_activation_hook(__FILE__, 'add_appl_db_field');
```

As you can see, we first invoke the application global scope ($app) in order to use the ORM to write our database query. Then, to avoid any errors, we do a check to make sure that the column does not exist. If it is does not exist, the the next query will alter the table and add the new column. Lastly, the *register\_activation\_hook()* will fire when the plugin is activated and therefore run the database query.

The second function in our boilerplate plugin, contains the instantiated custom field class as well as the custom field.

```
$app = \Liten\Liten::getInstance();

function new_application_field($appl)
{
    $field = _etsis_custom_field();
    $field->add('text', 'exam', 'Exam', $appl->exam, true);
    $field->build();
}
$app->hook->add_action('left_appl_view_dash_form', 'new_application_field', 1);
```

We will invoke the *left\_appl\_view\_dash\_form* action hook so that this new field will show up on the left side of the screen. Also notice the *$appl* variable which is actually the data object used to pull the value from the database. Since the new custom field is *exam*, our object is *$appl-&gt;exam*;

The last function is setting the value of the field when the form is submitted.

```
$app = \Liten\Liten::getInstance();

function update_application_field($appl)
{
    $appl->exam = $_POST['exam'];
}
$app->hook->add_action('update_application_db_table', 'update_application_field', 1);
```

Hopefully, with the details above along with the boilerplate plugin, you will be able to create the custom fields you need. However, if there is something you don’t understand or you need help, please feel free to post any comments or questions below.