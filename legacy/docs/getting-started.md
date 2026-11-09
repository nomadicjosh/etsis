Before going on, make sure that you are using the latest release of eduTrac SIS (etSIS). You can login [here](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/client/login/) or if you don’t have an account yet, you can [create a free account](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/client/signup/).

Trying to wade through the online manual and figuring out where to start could be a bit daunting. This table of contents / getting started guide should help you to better maneuver the online manual. There are quite a bit of setup screens and settings that need to happen before you can effectively use etSIS. It has been organized by priority and you should try to follow it as closely as possible.

Even though etSIS has been built for higher education, an alternative solution will be given for those who are trying to implement it for secondary schools. for example, secondary schools do not offer academic programs, however, on the academic program article, an alternative way to use this screen is provided. With this in mind, you should read through this getting started guide from beginning to end first before trying to setup your etSIS installation.

Last thing to note, most of the examples and screenshots are based on our demo system called Eastbound University which can be accessed at [http://demo.edutrac.net/](https://web.archive.org/web/20190818104919/http://demo.edutrac.net/). Logging in as admin and following along with this guide will help you better understand the functions and features of etSIS.

After a successful installation of etSIS, you need to navigate to the following setup screens.

## Administrative

### General Settings

Mostly everything on general settings is required. Navigate to this screen and make sure all the settings are set to your liking. Some screens rely on the internal REST API, so it is important that you enter an [api key](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/api-key/1.1/) on the general settings screen.

### Email settings

Emails are a very important feature in etSIS, so make sure the emails entered on this screen are all active and working. Please note that some servers disable sending and other features. If this is the case, you may need to download and install the [eduTrac SIS SMTP](https://web.archive.org/web/20190818104919/http://plugins.edutracsis.com/package/et-smtp/) plugin.

### Cronjob Handler

[Cronjob handlers](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/cronjob-handler/) are a very important part of making etSIS run smoothly and effectively. Some of these handlers can be set at a very low interval (i.e. 5 minutes) in order to keep data updated for ad-hoc reporting. Once the cronjob handlers have been added, you will need to make sure that you create a master cronjob on the server.

### Roles

eduTrac SIS is shipped with default user roles and [permissions](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/permissions-table/). However, you may need to update and/or create roles based on your institutions need. For example if you need a separate Registrar role instead of the stock Staff role, [create a new role](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/creating-customized-roles/), name it Registrar, and add the desired permissions to the role. Note that inquiry only permissions grants users read only access to particular screens. Navigate to the roles/permissions article to get a better understanding of the different permissions.

### Forms

Under the forms tab, there are several forms that will need to be filled out to near completion such as Acad Year, Semester, Term, School, Building, Location. There others can be filled out if needed on other screens. If you’ve purchased the importer module, you may import your data instead of entering it all by hand.

### Registration Settings

Now that the forms have been setup, you can now edit the registration settings. For more information on a particular field, you can click the questing mark icon for more information. These settings control the visualization of courses and course registration on the myeduTrac self service portal.

### Calendar Settings

If you’ve purchased the booking module, you need to set the calendar settings to meet your needs. The booking module allows for [classroom scheduling](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/classroom-scheduling/) on the SECT screen, room/event scheduling, as well as timetables for faculty and students.

### Human Resources

Visit this menu item to setup employee pay grades and job titles. Records will show up here when staff records are created.

### Financial

If you’ve purchased the financial module, make sure to set the proper paypal gateway settings. The financial module includes [billing tables](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/billing-table/), [student billing](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/create-bill/), [payments](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/record-payment/), fees, and [refunds](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/issue-refund/).

### Academic Programs

Now that you’ve completed the majority of the setup screens, you can start adding [academic programs](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/academic-program/). Academic programs are what connects a student to a particular degree path.

### Courses

[Courses](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/course/) are templates used to create course sections that students will register into. Courses are the parent and sections are the children. Courses can be used as a template from term to term unless course content and course description changes between term to term. Otherwise, the same course template can be used to make course sections from year to year.

### Course Sections

[Course sections](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/course-section/) are partitioned from courses. These are what students register into from term to term.

### Person Management

Under person management, there are several buckets. Everyone in etSIS comes under the [Person](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/person/) bucket. This is the main profile with username, password and initial address information. If the person is a prospective student, then he/she gets parsed out into the [application/applicant](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/student-application/) bucket. However, if you’ve purchased the myeT extended module, when the applicant applies online, he/she is automatically placed in both the person and applicant bucket. Once the prospective has gone through the application process, then the application gets stu’d or placed in the [student](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/create-student-record/) bucket. On the other hand, if the person is a faculty or staff member, then they are placed into the staff bucket. Once a student or staff record has been setup in the system, make sure to assign them the proper [role](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/assigning-user-roles/).

After configuring everything above, you should now have a system ready to work with. Everything else that follows has to do with daily functions and maintenance across several different offices and roles.

## Registrar

The registrar office usually oversees courses, course sections, registrations, and more. Below is a list of articles related to everyday operations in a registrar’s office.

### Creating courses (ACRS Screen)

Every now and again, a [new course](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/course/) will need to be created. Refer to this article if need be on understanding what the different fields are when creating a course.

### Cloning a Course

If for some reason a course become inactive and a new version of it needs to be created, you can [clone](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/cloning-a-course/) the old course without having to enter all the information manually.

### Creating sections

Creating the needed [course sections](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/course-section/) for each term may be the responsibility of someone in the registrar’s office or of a scheduler in other departments.

### Canceling course sections

Sometimes a course section may have been setup wrong or the course it was first created from has been updated. In any case, if there are students registered into the course section that needs to be cancelled, then you should follow the follow process: create a [batch registration query](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/batch-registration-query/), [batch register](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/batch-registration/) the students into the new course section, and then [cancel](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/cancel-course-sections/) the old course section.

### Transfer Credits

At some institutions, admissions may supply the registrar’s office with a student’s official transcript(s) from other institutions for transfer credit. Courses from other institutions may need to be added as [external courses](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/external-course/), and then [equated](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/transfer-course-equivalency/) before adding [transfer credits](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/transfer-credit/) to a student’s record.

### Classroom Scheduling

[Scheduling](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/classroom-scheduling/) course sections into classrooms is only available if you have the [booking module](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/client/cart/index/product/id/4/c/) installed.

### Course Registration

Students can register for courses via the myeduTrac self service portal or they can be [registered](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/course-registration/) by a staff member.

### Registration Status

A [registration status](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/registration-status/) is added when a student is registered into a particular course section. This is comes in handy when you need to know information about when a student was registered a who registered the student.

### Batch Registration

Registering a batch/group of students into a particular course section is only available if you have the [saved query module](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/client/cart/index/product/id/9/c/) installed. Follow the example of the [batch registration query](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/batch-registration-query/) in order to construct the query you need for [batch registration](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/batch-registration/) process.

### Add / Drop / Withdrawal

Throughout an institution’s add/drop period, some students may add new courses, drop courses or withdraw from the institution. Based on the time period at which these events can occur, the [add/dropping/withdrawal](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/add-drop-withdrawal/) of courses may or may not coincide with [withdrawing](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/student-hiatus/) from the institution.

### Student Academic Credits (STAC)

Think of [STAC](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/registration-status/) as a bucket of credits. When ever a student is registered into a course section, it will be viewable on the STAC screen which is available through SPRO. You can view more information about a particular student academic credit record through the student academic credit detail ([SACD](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/student-acad-cred-detail-screen/)) screen.

## Finance / Bursar / Student Accounts

In order to create student bills as well as other financial information, you will need to purchase and install the [financial module](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/client/cart/index/product/id/5/c/).

### Billing Tables

Visit the [billing tables](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/billing-table/) screens under the financial menu in order to set up the fees for generating student bills and/or adding fees.

### Create Bill

You can [generate a bill](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/create-bill/) for a student before or after a student registers for a particular term. Course, material and lab fees can be added on the course section billing screen (SBILL).

### Add Payment

Record student bill payments on the [add payment](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/add-payment/) screen.

### Issue Refund

Record student refunds on the [issue refund](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/issue-refund/) screen. You should only issue refunds if the student has a credit on his/her account. Otherwise, your billing will be incorrect.

### Batch Fees

You can generate [batch bills or fees](https://web.archive.org/web/20190818104919/https://www.edutracsis.com/kb/batch-fees/) by using the batch frees screen. You can generate bills or fees by certain populations: major, program, academic level. This is helpful if it is a bit cumbersome to generate bills one by one.