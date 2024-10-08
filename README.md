# LOM

See test.php for usage examples.

To see an example of what's possible with LOM, check out a game made using it:

https://freement.cloud/infini/bbalof.php?scenario=0

Version 0.6

Great optimizations. About 10 times faster in practical cases!

Version 0.5

LOM is now feature complete. It has been made faster by fixing up some bugs. If something goes wrong (which happens a lot less now!) you can use O::debug(); to give you the debug messages. Some bug fixes included imprecisions in internal encoding, a rework of the offset_depths array system to make it consistent throughout, adding a few little useful functions, less hacks to make things work (this is a great change, not assuming what code is coming in and thus being universal). Enjoy, have fun!

Version 0.4.5

Some small bug fixes and optimizations.

Version 0.4

This is an excellent version. Writing works properly now. It's now fractal and refinements could still be made. Forked from <a href="https://github.com/flaurora-sonora/LOM">LOM</a>.

Version 0.2

If you are manipulating XML with PHP then this is the code you want. LOM used to use array-based internal data structure but now uses string-based internal data structure. With this type of optimization it's about 10 times faster. Another improvement is that the external data structure (that which is accesible as the results of queries) is now the more familiar (to users of PHP regular expressions) string-offset pairs.

Version 0.1

LOM is an XML querying language; or slang, if you prefer. In terms of other querying languages: this one would be said to use a 
dynamic, rather than static, context. So query results depend on code-wise previous query results. Basically, it allows a coder 
to write code more lazily by having LOM assume that something not very specifically referenced should be looked for within the most relevant 
contexts (usually the most recent ones). This pushes it a little towards being conversational rather than only logical. An example
will probably clarify things:

Sarah: I want lots of friends. Do you have many friends?<br>
Jill: I have some but my brother has more.<br>
Sarah: Oh yeah, my brother has lots of friends too.<br>
Jill: What are their names?<br>

Based on the above conversation, we can probably see that what we are interested in would be the names of Sarah's brother's friends
and not the names of Jill's friends or the names of all the friends Sarah and Jill know or the names of everything in the universe.
LOM makes the syntax for this query simple; it would be $O->_('name'); assuming that the rest of the conversation were similarly coded.

See test.php for usage examples.
