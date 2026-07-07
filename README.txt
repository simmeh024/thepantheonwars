THE PANTHEON WARS — site files
================================

What's here
-----------
index.html   Home
books.html   All 14 books, grouped by phase
about.html   About the author (built from your foreword)
news.html    Blog/news posts + newsletter
css/style.css
js/main.js
images/      Character & world art pulled from your codex export

Security & Code Quality

This repository uses GitHub CodeQL analysis and Dependabot alerts to monitor code quality and dependency vulnerabilities. Security findings are reviewed and tracked as part of the development workflow.

Things to double check / finish
--------------------------------
1. Newsletter form: right now it just shows a "you're in" message in
   the browser — it doesn't actually collect emails anywhere. Sign up
   for something like Buttondown, ConvertKit, or Mailchimp and swap
   the <form action="#"> in each page for the endpoint they give you
   (see the comment at the top of js/main.js).
2. Publication status: I didn't add "Buy Now" buttons or claim any
   book is for sale, since I wasn't sure which (if any) are published
   yet. Tell me and I'll add retailer links/status badges.

