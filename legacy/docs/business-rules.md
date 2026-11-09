What is a Business Rule?
According to Wikipedia: A business rule is a rule that defines or constrains some aspect of business and always resolves to either true or false. Business rules are intended to assert business structure or to control or influence the behavior of the business.

Examples of Business Rules
A fee should be added to each student’s bill every term if they are in a particular degree program.
An award should be posted to each student who’s home location is South Carolina.
A student is assigned a particular faculty advisor if he/she is in a particular program.
Business policies or how they are defined could look different between each institution. With a business rule engine/logic, non technical staff will be able to create business rules to help implement some of those business policies without involving or waiting on the IT staff.

System Rules
Below is a list of current and upcoming screens which will allow you to create and attach business rules.

Rules Definition (RLDE)
Course Prerequisite (PRRL)
Billing Table (BTRL)
Graduation Honors (GHRL)
Advisor Assignment (ADVR)
Admissions Representative (AARS)
Class Levels (CLVR) & Student Load (STLD)
Registration Restriction (RRSR)

In eduTrac SIS (etSIS), you can use student restrictions to add FERPA, restrict a user from registering with 99 severity, and also set your own internal student restrictions. When a staff member uses the RGN screen, their is nothing stopping the staff member from registering the student with a 99 severity into a course.

At etSIS University, we are not bothered that a staff member can register a student into a course because the 99 severity could be conditional. However, we do have a business policy at etSIS University which states that if a student has a NOREG restriction with a 100 severity, even a staff member should not be allowed to register the student into a course.

Since this is a business policy and it is not a logic that is coded into etSIS, the question is how can we enforce this without it being a manual process of checking to make sure the student is eligible. The answer is creating a Registration Restriction Rule.

When Does the Rule Fire?
A registration restriction rule is fired or executed when a staff member tries to register a student into a course via the RGN screen.

How to Define?
To define a registration restriction rule, you must first visit the Rules Definition (RLDE) screen (see figure 1). Once you are there, you can define your business rule.

Description: Enter a short description of this rule.
Rule Code: Enter a unique code for this rule.
Department: What department does this restriction fall under. Only staff members who have permission to Access Forms, have Access to the Report Screen, or have Access to the Saved Query Screens will be able to add/view/edit rules.
Primary File: You must choose a primary file or database table to use. For this particular rule, you must use the person restrictions (PERC) file, and only the values belonging to this file.
Comment: This is optional and only for internal use to give more detail as to why this rule exists.