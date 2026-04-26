## Ongoing UI — Feature Prompt Template

Use this for any new UI page or component:

```
Build [PAGE NAME] in resources/js/Pages/[Domain]/[Page].vue

Controller: app/Http/Controllers/Web/[Domain]/[Controller].php
Route: [HTTP method] /[path] → [controller@method] named '[route.name]'

Inertia props from controller:
- [prop name]: [type and description]

Page layout:
- [describe the visual layout and sections]

Interactions:
- [describe user interactions, modals, slide-overs, form submissions]

Table columns (if applicable):
- [list columns]

Form fields (if applicable):
- [list fields with validation rules]

South African context:
- [any SA-specific formatting, rules, or labels]

Mobile considerations:
- [any mobile-specific behaviour]

Also create/update:
- Form Request: [RequestName] in app/Http/Requests/[Domain]/
- Add route to routes/web.php
- Add nav item to sidebar if this is a primary page
```
