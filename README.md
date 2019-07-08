# Code examples

Most files are just parts of bigger projects and cannot be used separately.

### JS
**js/callboards** - vue.js, example of a reusable component, which is used for displaying small widgets with data, 
like number of calls in queue, number of available agents, etc. Some widgets support colouring depending on their 
values (for example, 'agents available' widget turns red when all the agents are busy.

**js/channels-lineup** - example of vue.js component from Channels Lineup utility.

**js/widget** - jQuery-based widget. Implemented as jQuery plugin this widget can be installed on websites, whose 
owners would like to sell tickets online.

### PHP
**php/channel-lineup** - Laravel-based implementation of one controller, which is responsible for generating 
OpenAPI 3-compatible responses. It also has full api specification, which can be used for generating api 
documentation on the fly.

**php/graphql** - Yii-based implementation of GraphQL API, based on webonyx/graphql-php package.

**php/iot-device** - Zend framework-based example (although it mostly just pure PHP). This is a parser for messages 
from one type of iot metering devices. Lots of low-level bitwise operations. My oldest (5+ years) PHP code in this set.

**php/schedule-sync** - Part of the cinema schedule synchronisation tool. Yii-based, uses modern PHP syntax.

**php/system-mapper** - Graph builder (Laravel). System mapper was a tool that allowed users to get filtered list of 
dependent entities. For example, get cities by state, get nodes by city, get headends by ZIP and so on. All entities 
are related to each other, but at the same time there can be other objects between two entities that we are currently 
looking for. For example, Region and headend do not have direct relations, but we must be able to see all the 
headends assigned to a region and it can be done by adding related entities like Region -> City -> Node -> Headend. 
I solved this problem by implementing graph and shortest path algorithm.
