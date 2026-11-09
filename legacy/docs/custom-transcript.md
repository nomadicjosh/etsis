eduTrac SIS comes with a default transcript template. However, if you are hosting eduTrac SIS on your own server, you can create a custom transcript template that better meets your institution’s needs.

To create a custom transcript, make a copy of the default template located in */app/views/student/templates/transcript/*.

For example, let say you create a new template file called *eastbound.template.php*. In order for it to appear in the dropdown and be recognized by the system, you must include a header in your new template which will correlate with your template’s file name and look similar to the header below.

```
/*
Template Name: Custom College Transcript
Template Slug: eastbound
*/
```

Once the header is included and the template is added, the new template will appear in the dropdown option on the TRAN screen.

eduTrac use’s the [TCPDF](https://web.archive.org/web/20201019205821/http://www.tcpdf.org/examples.php) library to generate .pdf’s. When you head over to their site, you will see a plethora of examples that will help you with creating your own template. Please note that the code used to pull the information from the database cannot and should not be customized.