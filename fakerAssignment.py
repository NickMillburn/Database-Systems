#we need to import python's built-in sqlite3 module to interact with the
#SQLite database.  We are also going to use the csv module to read data
#from a CSV file
import csv
import sqlite3
import random
from datetime import datetime

import pandas as pd

#we need to create fake data for our csv file using the Faker module
from faker import Faker
from faker.providers import BaseProvider


#create our Faker object
fakeData = Faker()

#the genre generator was discussed in a different document.  notice that i added a couple of categories
class GenreGenerator(BaseProvider):
    def movie_genre(self):
        return random.choice(['Documentary', 'Thriller', 'Mystery', 'Horror', 'Action', 'Comedy', 'Drama', 'Romance', 'Suspense', 'Science Fiction'])

#add the generator to the fakeData object
fakeData.add_provider(GenreGenerator)

#we added this parameter in lecture, but in this assignment we won't really use it
class RatingGenerator(BaseProvider):
    def movie_rating(self):
        return random.choice(['G', 'PG', 'PG-13', 'R'])    

#once again, add the generator to the fakeData object
fakeData.add_provider(RatingGenerator)

#write a function to generate a movie name
#we will fake a movie name with words

def capitalize(str):
    return str.capitalize()

def get_movie_name():
    words = fakeData.words()
    capitalized_words = list(map(capitalize, words))
    movie_name = ' '.join(capitalized_words)
    return movie_name
    
#use faker to get a date for the movie; explore the difference between %B and %m, for example.  Be curious!
def get_movie_date():
    date = datetime.strftime(fakeData.date_time_this_decade(), "%B %d, %Y")
    return date

#we need a duration for the movie...between 50 and 150 minutes; maybe some movies are longer than 150 minutes, though...
def get_movie_length():
    movie_length = random.randrange(50, 150)
    return movie_length

#finally, we need a rotton tomatoes score...not for this assignment, but in future this might be useful to have
def get_movie_rating():
    movie_rating = round(random.uniform(1.0, 10.0), 1)
    return movie_rating

#we need a phone number.  explore how to get phone numbers in faker...and the process for formatting them uniformly
def generate_phone():
    area = random.randint(100, 999)
    prefix = random.randint(100, 999)
    suffix = random.randint(1000, 9999)
    #print(str(area) + '-' + str(prefix) + '-' + str(suffix))
    return str(area) + str(prefix) + str(suffix)
    
#we need to generate Actors, studios, Agents, and Producers.  We will actually populate the database for studios manually, since
#there aren't a lot of options...this is kind of frightening...just like the number of independent media outlets

#make studio list: Universal, Paramount, Warner Bros., Disney, and Sony Pictures
studio = ['Warner Bros.', 'Universal', 'Paramount', 'Disney', 'Sony Pictures']
email = ['warner@warner.com', 'uni@universal.com', 'par@paramount.com', 'mickey@disney.com', 'sony@sony.com']
phone = []
ceo = []
for i in range(0, len(studio)):
    phone.append(generate_phone())
    ceo.append(fakeData.first_name() + ' ' + fakeData.last_name())
    
#we need to include the studio name in our movie generation function, so we will
#need to create the faker provider
class StudioGenerator(BaseProvider):
    def get_studio(self):
        return random.choice(studio)    

#again, add the generator to fakeData...hopefully you are seeing the pattern.
fakeData.add_provider(StudioGenerator)


#write the file with studio data. using the with open structure, the file is automatically closed
#when the block is complete (even if there are errors).  For all of our files, the schema will be specified.
#the schema is Studio(name: text, Admin: text, phone: text, email: text)
#this process is a little different, because there are only 5 studios, and i didn't want to use fake data
#for everything.
with open('studio_data.csv', 'w', newline = '') as csvfile:
    writer = csv.writer(csvfile)
    writer.writerow(['Studio', 'Admin', 'Phone', 'email'])
    for i in range(0, len(studio)):
        myStudio = [studio[i], ceo[i], str(phone[i]), email[i]]
        writer.writerow(myStudio)

#next, we will write the file with the producer data
#we need a random ID for the producer ID, random names, random contact information (phone number and e-mail address)
#i'll use faker for part of the email address
#the schema is Producer(ProducerID: text, name: text, phone: text, email: text)

#we'll also want these producers to be able to be inserted into our Movie table, so we need to be able to reference
#this information when we make our fake data...this is kind of important if we want our data in our tables to be connected.
producerIDList = []

with open('producer_data.csv', 'w', newline = '') as csvfile:
    writer = csv.writer(csvfile)
    writer.writerow(['ProducerID', 'Name', 'Phone', 'email'])
    for i in range(0, 20):
        prodID = random.randint(10000, 99999)
        producerIDList.append(str(prodID))
        first = fakeData.first_name() 
        last = fakeData.last_name()
        prodName = first + ' ' + last
        email = first + last + '@' + fakeData.free_email_domain()
        myProducer = [prodID, prodName, str(generate_phone()), email]
        writer.writerow(myProducer)

#once again, we'll need to create the faker provider so that we can pull this information into subsequent tables
class ProducerGenerator(BaseProvider):
    def get_producer(self):
        return random.choice(producerIDList)    

fakeData.add_provider(ProducerGenerator)

#next, let's create the agents.  These will be similar to the process for creating the producers
#the schema is Agent(agentID: text, name: text, phone: text, email: text)

#now, since each actor is going to have an agent, we'll want these agents to be able to be used in our
#fake data for an actor.  So, we'll need the list of agentIDs to insert into our actor table
agentIDList = []

with open('agent_data.csv', 'w', newline = '') as csvfile:
    writer = csv.writer(csvfile)
    writer.writerow(['AgentID', 'Name', 'Phone', 'email'])
    for i in range(0, 20):
        agentID = random.randint(100000, 999999)
        #write the rest of this function here, making sure to keep track of the agent IDs!
        #agentID is the key for Agent, so re-roll on the rare collision
        while str(agentID) in agentIDList:
            agentID = random.randint(100000, 999999)
        agentIDList.append(str(agentID))
        first = fakeData.first_name()
        last = fakeData.last_name()
        agentName = first + ' ' + last
        email = first + last + '@' + fakeData.free_email_domain()
        myAgent = [agentID, agentName, str(generate_phone()), email]
        writer.writerow(myAgent)


#we need to create the faker provider
class AgentGenerator(BaseProvider):
    def get_agent(self):
        return random.choice(agentIDList)

#add the generator to fakeData
fakeData.add_provider(AgentGenerator)

#now, let's create our actors
#the schema is Actor(name: text, agentID: text, gender: text, age: integer)
#we will use faker to populate the agentID field, and we will randomly generate male/female actors
#we could get more sophisticated by using gender neutral names...
#we will need to insert our actors into our movie table, so we need a list of actors for that purpose
#the schema for this relation is Actor(name: text, agentID: text, gender: text, age: integer)
actorList = []

with open('actor_data.csv', 'w', newline = '') as csvfile:
    writer = csv.writer(csvfile)
    writer.writerow(['Name', 'AgentID', 'Gender', 'Age'])
    for i in range(0, 30):
        g = random.randint(0, 2)
        if g == 0:
            first = fakeData.first_name_female()
            gender = 'F'
        elif g == 1:
            first = fakeData.first_name_male()
            gender = 'M'
        else:
            first = fakeData.first_name_nonbinary()
            gender = 'N'
        last = fakeData.last_name()
        actorName = first + ' ' + last
        #Performance identifies an actor by name alone, so two actors can't share one
        while actorName in actorList:
            last = fakeData.last_name()
            actorName = first + ' ' + last
        #add the actor's name to the list of actors
        actorList.append(actorName)
        #create a random age between 15 and 70
        age = random.randint(15, 70)
        myActor = [actorName, fakeData.get_agent(), gender, age]
        writer.writerow(myActor)


#print(generate_movie())
#now we can create the data for a movie.  the schema for a movie is
#Movie(title: text, year: integer, duration: integer, genre: text, studio: text, prodID: text)

#(title, year) pairs, kept so the performance table can reference real movies
movieList = []

with open('movie_data.csv', 'w', newline = '') as csvfile:
    writer = csv.writer(csvfile)
    writer.writerow(['Title', 'Year', 'Runtime', 'Genre', 'Studio', 'ProducerID'])
    for n in range(1, 100):
        #create a movie based on the schema provided
        title = get_movie_name()
        year = fakeData.date_time_this_decade().year
        #(title, year) is the key for Movie, so re-roll on the rare collision
        while (title, year) in movieList:
            title = get_movie_name()
        movieList.append((title, year))
        myMovie = [title, year, get_movie_length(), fakeData.movie_genre(), fakeData.get_studio(), fakeData.get_producer()]
        writer.writerow(myMovie)


#we can put everything together to create a performance table
#the schema is Performance(title: text, year: date, actor: text)
#in this case, a movie can have multiple actors, but an actor cannot appear in the same movie twice
#so, you'll want to make sure that you avoid duplicates of a particular row...
#if you want to earn a bonus, ensure that tuples are unique.  you can use other libraries, but you should provide details
#on your logic.  the amount of the bonus will be a function of the quality of your approach, as well as the uniqueness.
#everyone following the same approach will be less impressive than someone coming up with an efficient, streamlined process.
with open('performance_data.csv', 'w', newline = '') as csvfile:
    writer = csv.writer(csvfile)
    #do the rest of the stuff here.
    writer.writerow(['Title', 'Year', 'Actor'])
    #uniqueness is guaranteed by construction instead of by checking afterwards:
    #every (title, year) in movieList is distinct, every name in actorList is distinct,
    #and random.sample draws a cast without replacement, so no actor repeats within a movie.
    #that makes every (title, year, actor) tuple unique in one pass, with no retries,
    #no set of seen rows, and no dedup step, and every movie gets at least one actor.
    for title, year in movieList:
        cast = random.sample(actorList, random.randint(1, 5))
        for actor in cast:
            writer.writerow([title, year, actor])
