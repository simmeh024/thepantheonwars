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

Going live on thepantheonwars.com
----------------------------------
This is a plain static site — no build step. Upload this whole folder
to any host (Netlify, Vercel, Cloudflare Pages, GitHub Pages, or your
registrar's hosting) and point thepantheonwars.com at it. index.html
is the homepage.

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
3. Books 8, 9, 11, 13 don't have titles yet in your notes, so they're
   marked "Title Forthcoming" with a teaser line. Send me titles/blurbs
   whenever they're ready and I'll drop them in.
4. Contact info: I left your email off the public site by default.
   Let me know if you want a contact address or social links in the
   footer.
5. Character/world art came from your codex export's thumbnails
   (250x250px). They look fine at the sizes used here, but if you want
   larger, sharper hero images, higher-res versions of those pieces
   would upgrade the look.
